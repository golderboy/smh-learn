"""Build a source-only release archive from an explicit allowlist, not the working tree."""
import hashlib
import json
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parent.parent
DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'docker', 'lang', 'resources', 'routes', 'tests', '.github']
FILES = ['.dockerignore', '.editorconfig', '.env.example', '.env.production.example', '.gitattributes', '.gitignore',
         '.npmrc', '.prettierrc.json', 'artisan', 'compose.yaml', 'compose.production.yaml', 'composer.json', 'composer.lock',
         'package.json', 'package-lock.json', 'phpstan.neon', 'phpunit.xml', 'tsconfig.json', 'vite.config.ts',
         'vitest.config.ts', 'playwright.config.ts', 'eslint.config.mjs', 'README.md', 'CHANGELOG.md', 'LICENSE']
DOCS = ['INSTALL_ALMALINUX9.md', 'INSTALL_UBUNTU.md', 'INSTALL_WINDOWS.md', 'PRODUCTION_OPERATIONS.md', 'PRODUCTION_1_0_VERIFICATION.md',
        'DEV_SETUP_GUIDE.md',
        'MASTER_DATA_IMPORT_GUIDE.md', 'THAID_INTEGRATION_GUIDE.md', 'COBALT_CORAL_SETTINGS_GUIDE.md',
        'COURSE_LESSON_DEV_GUIDE.md', 'PRODUCTION_DEPLOYMENT_CHECKLIST.md', 'UAT_PHASE0_6.md']


def selected():
    paths = [ROOT / name for name in FILES if (ROOT / name).is_file()]
    for folder in DIRECTORIES:
        paths.extend(path for path in (ROOT / folder).rglob('*') if path.is_file())
    paths.extend(path for path in (ROOT / 'public').rglob('*') if path.is_file() and not path.relative_to(ROOT / 'public').parts[0] in ['build', 'storage', 'hot'])
    paths.extend(ROOT / 'docs' / name for name in DOCS if (ROOT / 'docs' / name).is_file())
    paths.extend(path for path in (ROOT / 'docs/templates').glob('*.xlsx') if path.is_file())
    paths.extend(path for path in (ROOT / 'docs/manuals').rglob('*') if path.is_file())
    paths.extend(path for path in (ROOT / 'scripts').glob('*') if path.is_file() and path.suffix in ['.py', '.sh', '.mjs', '.ps1'])
    paths.extend(path for path in (ROOT / 'storage').rglob('.gitignore') if path.is_file())
    return sorted(set(path for path in paths if not path.is_symlink() and not (path.parent == ROOT / 'bootstrap/cache' and path.suffix == '.php')))


def main():
    folder = ROOT / 'release'
    folder.mkdir(exist_ok=True)
    archive = folder / 'SMH-Learning-1.0.0-source.zip'
    paths = selected()
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as output:
        for path in paths:
            output.write(path, str(path.relative_to(ROOT)).replace('\\', '/'))
    sha256 = hashlib.sha256(archive.read_bytes()).hexdigest()
    (folder / (archive.name + '.sha256')).write_text(sha256 + '  ' + archive.name + '\n', encoding='ascii')
    print(json.dumps({'archive': archive.name, 'files': len(paths), 'bytes': archive.stat().st_size, 'sha256': sha256}))


if __name__ == '__main__':
    main()
