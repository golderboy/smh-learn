"""Restore only into a NEW smh-learning-restore-* project with an empty database."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parent.parent


def dc(*args, stdin=None):
    result = subprocess.run(['docker', 'compose', '--env-file', '.env.production', '-f', 'compose.production.yaml', *args],
                            cwd=ROOT, stdin=stdin, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
    if result.returncode:
        raise RuntimeError('Restore Docker operation failed; inspect isolated service logs securely.')
    return result.stdout


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--manifest', type=Path, required=True)
    parser.add_argument('--passphrase-file', type=Path, required=True)
    parser.add_argument('--gpg', default='gpg')
    args = parser.parse_args()
    config = json.loads(dc('config', '--format', 'json'))
    env = config['services']['app']['environment']
    if not re.fullmatch(r'smh-learning-restore-[a-z0-9-]+', config['name']) or env.get('APP_ENV') != 'production' or env.get('DB_DATABASE') in ['smh_learning_dev', 'smh_learning_test']:
        raise RuntimeError('Refusing restore outside an isolated smh-learning-restore-* project.')
    if dc('ps', '-q').strip():
        raise RuntimeError('Restore target must have no running services. Stop only the isolated target first.')
    manifest = json.loads(args.manifest.read_text(encoding='utf-8'))
    if manifest.get('environment') != 'production' or manifest.get('verified') is not True or manifest.get('sha256') != digest(args.archive):
        raise RuntimeError('Encrypted archive verification failed.')
    cache = ROOT / '.cache'
    cache.mkdir(mode=0o700, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='restore-', dir=cache) as temporary:
        working = Path(temporary)
        plain = working / 'backup.tar.gz'
        result = subprocess.run([args.gpg, '--batch', '--pinentry-mode', 'loopback', '--passphrase-file', str(args.passphrase_file.resolve()),
                                 '--output', str(plain), '--decrypt', str(args.archive.resolve())],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
        if result.returncode:
            raise RuntimeError('Backup decryption failed.')
        with tarfile.open(plain, 'r:gz') as tar:
            for member in tar.getmembers():
                path = PurePosixPath(member.name)
                if path.is_absolute() or '..' in path.parts or '\\' in member.name or not path.parts or path.parts[0] not in ['database.sql', 'storage', 'runtime.env', 'inventory.json'] or not (member.isfile() or member.isdir()):
                    raise RuntimeError('Unsafe backup archive member; refused.')
                if path.parts[0] != 'storage' and (len(path.parts) != 1 or not member.isfile()):
                    raise RuntimeError('Unexpected backup archive structure.')
            tar.extractall(working)
        original = (working / 'runtime.env').read_text(encoding='utf-8')
        match = re.search(r'^APP_KEY=(base64:[A-Za-z0-9+/=]+)$', original, re.MULTILINE)
        if not match:
            raise RuntimeError('Original APP_KEY missing from encrypted backup.')
        dc('up', '-d', '--wait', 'mariadb', 'redis')
        tables = dc('exec', '-T', 'mariadb', 'sh', '-c',
                    'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot "$MARIADB_DATABASE" -N -e "SHOW TABLES"')
        if tables.strip():
            raise RuntimeError('Restore target database is not empty; refused to overwrite tables.')
        target = ROOT / '.env.production'
        updated = re.sub(r'^APP_KEY=.*$', 'APP_KEY=' + match[1], target.read_text(encoding='utf-8'), flags=re.MULTILINE)
        target.write_text(updated, encoding='utf-8')
        os.chmod(target, 0o600)
        with (working / 'database.sql').open('rb') as stream:
            dc('exec', '-T', 'mariadb', 'sh', '-c',
               'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot "$MARIADB_DATABASE"', stdin=stream)
        dc('up', '-d', '--wait', 'app')
        dc('cp', str(working / 'storage') + '/.', 'app:/var/www/html/storage/app')
        dc('exec', '-T', '--user', 'root', 'app', 'chown', '-R', '33:33', 'storage')
        expected = json.loads((working / 'inventory.json').read_text(encoding='utf-8'))
        actual = json.loads(dc('exec', '-T', 'app', 'php', 'artisan', 'ops:backup-inventory'))
        if actual['tables'] != expected['tables'] or actual['files'] != expected['files']:
            raise RuntimeError('Restore reconciliation failed. Keep the target isolated for review.')
        dc('up', '-d', '--wait')
        print(json.dumps({'restored': True, 'project': config['name'], 'tables_verified': len(actual['tables']),
                          'files_verified': len(actual['files']), 'original_app_key_preserved': True}))


def digest(path):
    result = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(chunk)
    return result.hexdigest()


if __name__ == '__main__':
    main()
