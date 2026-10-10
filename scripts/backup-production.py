"""Consistent encrypted production backup; never resets or restores a database."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import time
import uuid
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parent.parent


def dc(*args, stdout=None):
    result = subprocess.run(['docker', 'compose', '--env-file', '.env.production', '-f', 'compose.production.yaml', *args],
                            cwd=ROOT, stdout=stdout or subprocess.PIPE, stderr=subprocess.PIPE, check=False)
    if result.returncode:
        raise RuntimeError('Docker backup operation failed; inspect local service logs securely.')
    return result.stdout


def inventory():
    return json.loads(dc('exec', '-T', 'app', 'php', 'artisan', 'ops:backup-inventory'))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--passphrase-file', type=Path, required=True)
    parser.add_argument('--gpg', default='gpg')
    args = parser.parse_args()
    secret = args.passphrase_file.resolve(strict=True)
    if not shutil.which(args.gpg) or not secret.is_file() or secret.stat().st_size < 20:
        parser.error('GnuPG and a private passphrase file (at least 20 bytes) are required.')
    if os.name == 'posix' and secret.stat().st_mode & 0o077:
        parser.error('Passphrase file must not be readable by group/others (chmod 600).')
    if inventory()['maintenance']:
        raise RuntimeError('Application is already in maintenance mode; refused.')
    folder = ROOT / '.backups'
    folder.mkdir(mode=0o700, exist_ok=True)
    lock = folder / '.production-backup.lock'
    fd = os.open(lock, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    os.close(fd)
    backup_id = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ-') + uuid.uuid4().hex[:12]
    archive = folder / (backup_id + '.tar.gz.gpg')
    maintenance = False
    running_workers = []
    try:
        dc('exec', '-T', 'app', 'php', 'artisan', 'down', '--retry=60')
        maintenance = True
        running_workers = [name for name in dc('ps', '--status', 'running', '--services').decode().splitlines()
                           if name in ['queue-worker', 'scheduler']]
        if running_workers:
            dc('stop', *running_workers)
        before = inventory()
        with tempfile.TemporaryDirectory(prefix='.backup-', dir=folder) as temporary:
            working = Path(temporary)
            with (working / 'database.sql').open('wb') as stream:
                dc('exec', '-T', 'mariadb', 'sh', '-c',
                   'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump -uroot --single-transaction --routines --events --triggers --hex-blob --default-character-set=utf8mb4 "$MARIADB_DATABASE"', stdout=stream)
            dc('cp', 'app:/var/www/html/storage/app', str(working / 'storage'))
            shutil.copyfile(ROOT / '.env.production', working / 'runtime.env')
            (working / 'inventory.json').write_text(json.dumps(before), encoding='utf-8')
            after = inventory()
            if before != after:
                raise RuntimeError('Database or uploaded files changed during backup; refused.')
            plain = working / 'backup.tar.gz'
            with tarfile.open(plain, 'w:gz') as tar:
                for name in ['database.sql', 'storage', 'runtime.env', 'inventory.json']:
                    tar.add(working / name, arcname=name)
            result = subprocess.run([args.gpg, '--batch', '--pinentry-mode', 'loopback', '--passphrase-file', str(secret),
                                     '--symmetric', '--cipher-algo', 'AES256', '--output', str(archive), str(plain)],
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
            if result.returncode:
                raise RuntimeError('GnuPG encryption failed; no verified backup created.')
            # Decrypt once and compare the archive hash before marking a backup verified.
            verified = working / 'verified.tar.gz'
            result = subprocess.run([args.gpg, '--batch', '--pinentry-mode', 'loopback', '--passphrase-file', str(secret),
                                     '--output', str(verified), '--decrypt', str(archive)],
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
            if result.returncode or digest(verified) != digest(plain):
                raise RuntimeError('Encrypted backup verification failed.')
        os.chmod(archive, 0o600)
        manifest = {'format': 1, 'id': backup_id, 'environment': 'production', 'verified': True,
                    'created_at': int(time.time()), 'bytes': archive.stat().st_size, 'sha256': digest(archive)}
        (folder / (backup_id + '.json')).write_text(json.dumps(manifest, indent=2), encoding='utf-8')
        dc('exec', '-T', 'app', 'php', '-r',
           '$p="storage/app/ops"; if(!is_dir($p)){mkdir($p,0750,true);} file_put_contents($p."/last-backup.json",$argv[1]);', json.dumps(manifest))
        print(json.dumps(manifest))
    finally:
        try:
            if maintenance:
                dc('exec', '-T', 'app', 'php', 'artisan', 'up')
        finally:
            try:
                if running_workers:
                    dc('up', '-d', *running_workers)
            finally:
                lock.unlink(missing_ok=True)


def digest(path):
    result = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(chunk)
    return result.hexdigest()


if __name__ == '__main__':
    main()
