"""Verify PHP-backed HTTP routes after starting the production services."""
import json
from pathlib import Path
import subprocess
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
command = ['docker', 'compose', '--env-file', '.env.production', '-f', 'compose.production.yaml']
config = json.loads(subprocess.check_output([*command, 'config', '--format', 'json'], cwd=ROOT))
port = config['services']['nginx']['ports'][0]['published']
for path in ['/up', '/login']:
    with urllib.request.urlopen('http://127.0.0.1:' + str(port) + path, timeout=15) as response:
        body = response.read().decode('utf-8')
        if response.status != 200 or (path == '/login' and 'csrf-token' not in body):
            raise RuntimeError('Production HTTP verification failed: ' + path)
print(json.dumps({'production_http_verified': True, 'project': config['name'], 'routes': ['/up', '/login']}))
