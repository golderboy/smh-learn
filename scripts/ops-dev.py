"""DEV-only backup and isolated restore drill. No writes to the source database."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import tarfile
import time
import uuid
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parent.parent
BACKUPS = ROOT / '.backups'
DRILLS = ROOT / 'storage/app/restore-drills'
ID = re.compile(r'^\d{8}T\d{6}Z-[a-f0-9]{12}$')


def docker(*args, stdin=None, stdout=None):
    result = subprocess.run(['docker', 'compose', *args], cwd=ROOT, input=stdin,
                            stdout=stdout or subprocess.PIPE, stderr=subprocess.PIPE, check=False)
    if result.returncode:
        # Provider/DB/process errors can contain credentials. Do not echo raw stderr.
        raise RuntimeError(f'Docker operation failed ({result.returncode}); inspect local logs securely.')
    return result.stdout


def artisan(*args):
    return docker('exec', '-T', 'app', 'php', 'artisan', *args)


def digest(path):
    h = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()


def snapshot(*args):
    return json.loads(artisan('ops:snapshot', *args))


def contained(path, parent):
    path = path.resolve()
    parent = parent.resolve()
    if path == parent or not path.is_relative_to(parent):
        raise RuntimeError('Refusing a path outside the intended directory.')
    return path


def verify(backup_id):
    if not ID.fullmatch(backup_id):
        raise RuntimeError('Invalid backup identifier.')
    folder = contained(BACKUPS / backup_id, BACKUPS)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if manifest.get('format') != 1 or manifest.get('environment') != 'local' or manifest.get('id') != backup_id:
        raise RuntimeError('Backup is not a recognized DEV backup.')
    archive = folder / 'backup.tar.gz'
    if digest(archive) != manifest['sha256'] or archive.stat().st_size != manifest['bytes']:
        raise RuntimeError('Backup archive verification failed.')
    return folder, manifest


def backup():
    # Guard before maintenance mode or worker changes.
    baseline = snapshot()
    if baseline['environment'] != 'local' or (ROOT / 'storage/framework/down').exists():
        raise RuntimeError('DEV guard failed or application is already in maintenance mode.')
    services = docker('ps', '--format', 'json').decode()
    running = [item['Service'] for item in (json.loads(line) for line in services.splitlines() if line.strip())
               if item['State'] == 'running']
    workers = [name for name in ['queue-worker', 'scheduler'] if name in running]
    backup_id = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ-') + uuid.uuid4().hex[:12]
    folder = contained(BACKUPS / backup_id, BACKUPS)
    BACKUPS.mkdir(exist_ok=True)
    lock = BACKUPS / '.backup.lock'
    try:
        lock_handle = lock.open('x')
    except FileExistsError:
        raise RuntimeError('Another backup may be running; inspect the lock before retrying.')
    lock_handle.close()
    maintenance = False
    stopped = False
    try:
        folder.mkdir(parents=True)
        maintenance = True
        artisan('down', '--retry=60')
        if workers:
            stopped = True
            docker('stop', *workers)
        before = snapshot()
        sql = folder / 'database.sql'
        with sql.open('wb') as output:
            docker('exec', '-T', 'mariadb', 'sh', '-c',
                   'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump -uroot --single-transaction --routines --events --triggers --hex-blob --default-character-set=utf8mb4 smh_learning_dev', stdout=output)
        (folder / 'snapshot.json').write_text(json.dumps(before, ensure_ascii=False), encoding='utf-8')
        archive = folder / 'backup.tar.gz'
        with tarfile.open(archive, 'w:gz', compresslevel=1) as tar:
            tar.add(sql, arcname='database.sql', recursive=False)
            tar.add(folder / 'snapshot.json', arcname='snapshot.json', recursive=False)
            for relative in before['files']:
                file = contained(ROOT / 'storage/app' / relative, ROOT / 'storage/app')
                if file.is_symlink() or digest(file) != before['files'][relative]['sha256']:
                    raise RuntimeError('Uploaded files changed during the backup.')
                tar.add(file, arcname='storage/' + relative, recursive=False)
        if snapshot() != before:
            raise RuntimeError('Database/storage changed during the backup; archive is not accepted.')
        manifest = {'format': 1, 'id': backup_id, 'environment': 'local', 'created_at': int(time.time()),
                    'bytes': archive.stat().st_size, 'sha256': digest(archive),
                    'tables': len(before['tables']), 'files': len(before['files'])}
        (folder / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
        verify(backup_id)
        # Remove redundant plain files only within this newly created backup directory.
        sql.unlink()
        (folder / 'snapshot.json').unlink()
        status = ROOT / 'storage/app/ops/last-backup.json'
        status.parent.mkdir(parents=True, exist_ok=True)
        temporary = status.with_suffix('.tmp')
        temporary.write_text(json.dumps({**manifest, 'verified': True, 'restore_tested': False}), encoding='utf-8')
        temporary.replace(status)
        print(json.dumps({'ok': True, 'backup': manifest}, indent=2))
    finally:
        try:
            if maintenance:
                artisan('up')
        finally:
            try:
                if stopped:
                    docker('start', *workers)
            finally:
                lock.unlink(missing_ok=True)


def restore(backup_id):
    snapshot()  # Must still be the local DEV app.
    folder, manifest = verify(backup_id)
    suffix = uuid.uuid4().hex[:12]
    database = 'smh_restore_' + suffix
    restore_user = 'smhr_' + suffix
    restore_password = uuid.uuid4().hex + uuid.uuid4().hex
    client_file = '/tmp/smh-restore-' + suffix + '.cnf'
    target = contained(DRILLS / suffix, DRILLS)
    target.mkdir(parents=True)
    created = False
    start = time.monotonic()
    try:
        with tarfile.open(folder / 'backup.tar.gz', 'r:gz') as tar:
            members = tar.getmembers()
            names = [item.name for item in members]
            if len(names) != len(set(names)) or 'database.sql' not in names or 'snapshot.json' not in names:
                raise RuntimeError('Invalid backup members.')
            for member in members:
                if '..' in PurePosixPath(member.name).parts or '\\' in member.name or ':' in member.name:
                    raise RuntimeError('Refusing archive path traversal.')
                if not member.isfile() or not (member.name in ['database.sql', 'snapshot.json']
                                              or member.name.startswith(('storage/private/', 'storage/public/'))):
                    raise RuntimeError('Refusing an unsafe archive member.')
                path = contained(target / member.name, target)
                path.parent.mkdir(parents=True, exist_ok=True)
                with tar.extractfile(member) as source, path.open('wb') as output:
                    shutil.copyfileobj(source, output)
        expected = json.loads((target / 'snapshot.json').read_text(encoding='utf-8'))
        docker('exec', '-T', 'mariadb', 'sh', '-c',
               'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot',
               stdin=f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'.encode())
        created = True
        # The import account can only modify this isolated database, even if the SQL is malformed.
        docker('exec', '-T', 'mariadb', 'sh', '-c', 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot',
               stdin=f"CREATE USER '{restore_user}'@'localhost' IDENTIFIED BY '{restore_password}'; GRANT ALL ON `{database}`.* TO '{restore_user}'@'localhost';".encode())
        docker('exec', '-T', 'mariadb', 'sh', '-c', 'umask 077; cat > ' + client_file,
               stdin=f'[client]\nuser={restore_user}\npassword={restore_password}\n'.encode())
        with (target / 'database.sql').open('rb') as sql:
            result = subprocess.run(['docker', 'compose', 'exec', '-T', 'mariadb', 'sh', '-c',
                                     f'exec mariadb --defaults-extra-file={client_file} {database}'],
                                    cwd=ROOT, stdin=sql, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
            if result.returncode:
                raise RuntimeError('Isolated database import failed; source database is untouched.')
        actual = snapshot('--database=' + database, '--storage=/var/www/html/storage/app/restore-drills/' + suffix + '/storage')
        if actual != expected:
            raise RuntimeError('Restore reconciliation failed: table rows/hashes or file hashes differ.')
        result = {'ok': True, 'backup_id': backup_id, 'tables': len(actual['tables']),
                  'rows': sum(item['rows'] for item in actual['tables'].values()), 'files': len(actual['files']),
                  'bytes': sum(item['bytes'] for item in actual['files'].values()),
                  'restore_seconds': round(time.monotonic() - start, 2), 'source_database_untouched': True}
        report = ROOT / 'storage/app/ops/last-restore.json'
        report.parent.mkdir(parents=True, exist_ok=True)
        report.write_text(json.dumps(result, indent=2), encoding='utf-8')
        status = ROOT / 'storage/app/ops/last-backup.json'
        if status.exists():
            last = json.loads(status.read_text(encoding='utf-8'))
            if last.get('id') == backup_id:
                last['restore_tested'] = True
                status.write_text(json.dumps(last), encoding='utf-8')
        print(json.dumps(result, indent=2))
    finally:
        if created:
            if not re.fullmatch(r'smh_restore_[a-f0-9]{12}', database):
                raise RuntimeError('Refusing unsafe database cleanup.')
            docker('exec', '-T', 'mariadb', 'sh', '-c', 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot',
                   stdin=f"DROP USER IF EXISTS '{restore_user}'@'localhost'; DROP DATABASE `{database}`;".encode())
            docker('exec', '-T', 'mariadb', 'rm', '-f', client_file)
        shutil.rmtree(contained(target, DRILLS))


def retention_plan():
    copies = []
    if BACKUPS.exists():
        for item in BACKUPS.iterdir():
            if item.is_dir() and ID.fullmatch(item.name) and (item / 'manifest.json').is_file():
                _, manifest = verify(item.name)
                copies.append(manifest)
    copies.sort(key=lambda item: item['created_at'], reverse=True)
    keep = set()
    for pattern, count in [('%Y-%m-%d', 7), ('%G-%V', 4), ('%Y-%m', 12)]:
        periods = set()
        for item in copies:
            period = datetime.fromtimestamp(item['created_at'], timezone.utc).strftime(pattern)
            if period not in periods and len(periods) < count:
                periods.add(period)
                keep.add(item['id'])
    print(json.dumps({'policy': {'daily': 7, 'weekly': 4, 'monthly': 12},
                      'keep': sorted(keep), 'eligible_for_removal': [item['id'] for item in copies if item['id'] not in keep],
                      'deletion_performed': False}, indent=2))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['backup', 'restore-test', 'verify', 'retention-plan'])
    parser.add_argument('backup_id', nargs='?')
    args = parser.parse_args()
    try:
        if args.action == 'backup':
            backup()
        elif args.action == 'restore-test':
            restore(args.backup_id or '')
        elif args.action == 'verify':
            _, result = verify(args.backup_id or '')
            print(json.dumps({'ok': True, 'backup': result}, indent=2))
        else:
            retention_plan()
    except (RuntimeError, OSError, ValueError, tarfile.TarError) as error:
        parser.exit(1, str(error) + '\n')
