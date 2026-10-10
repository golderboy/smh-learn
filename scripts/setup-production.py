"""Generate a NEW production configuration without modifying an existing file."""
import argparse
import base64
import os
from pathlib import Path
import re
import secrets

ROOT = Path(__file__).resolve().parent.parent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--domain', required=True, help='DNS hostname, without https:// or a path')
    parser.add_argument('--port', type=int, default=8080)
    parser.add_argument('--project', default='smh-learning-production')
    args = parser.parse_args()
    if not re.fullmatch(r'(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}', args.domain):
        parser.error('Provide a lowercase DNS hostname.')
    if not 1024 <= args.port <= 65535 or not re.fullmatch(r'smh-[a-z0-9-]{1,50}', args.project) or args.project in ['smh-learning-dev', 'smh-learning-test']:
        parser.error('Invalid loopback port or project name (must start with smh-).')
    target = ROOT / '.env.production'
    if target.exists():
        parser.error('.env.production already exists; refused to overwrite keys or credentials.')
    values = {
        'APP_URL': 'https://' + args.domain,
        'APP_PORT': str(args.port),
        'SMH_PROJECT_NAME': args.project,
        'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
        'DB_PASSWORD': secrets.token_hex(32),
        'MARIADB_ROOT_PASSWORD': secrets.token_hex(32),
    }
    lines = []
    for line in (ROOT / '.env.production.example').read_text(encoding='utf-8').splitlines():
        key = line.partition('=')[0]
        lines.append(key + '=' + values[key] if key in values else line)
    fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as stream:
        stream.write('\n'.join(lines) + '\n')
    print('Created .env.production with new random secrets. No secrets printed.')


if __name__ == '__main__':
    main()
