import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

module_path = Path(__file__).resolve().parents[2] / 'scripts/ops-dev.py'
spec = importlib.util.spec_from_file_location('ops_dev', module_path)
ops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ops)


class BackupSafetyTests(unittest.TestCase):
    def test_path_escape_or_parent_itself_is_rejected(self):
        with tempfile.TemporaryDirectory(dir=module_path.parent.parent / '.cache') as folder:
            parent = Path(folder)
            for child in [parent, parent / '..' / 'outside']:
                with self.assertRaises(RuntimeError):
                    ops.contained(child, parent)
            self.assertEqual(parent / 'safe', ops.contained(parent / 'safe', parent))

    def test_tampered_archive_and_invalid_backup_identifier_are_rejected(self):
        previous = ops.BACKUPS
        with tempfile.TemporaryDirectory(dir=module_path.parent.parent / '.cache') as folder:
            ops.BACKUPS = Path(folder)
            try:
                backup_id = '20261008T000000Z-0123456789ab'
                target = ops.BACKUPS / backup_id
                target.mkdir()
                archive = target / 'backup.tar.gz'
                archive.write_bytes(b'original synthetic archive')
                manifest = {'format': 1, 'environment': 'local', 'id': backup_id,
                            'sha256': ops.digest(archive), 'bytes': archive.stat().st_size}
                (target / 'manifest.json').write_text(json.dumps(manifest))
                self.assertEqual(manifest, ops.verify(backup_id)[1])
                archive.write_bytes(b'tampered synthetic archive')
                with self.assertRaises(RuntimeError):
                    ops.verify(backup_id)
                for identifier in ['../outside', 'smh_learning_dev', '/absolute', '']:
                    with self.assertRaises(RuntimeError):
                        ops.verify(identifier)
            finally:
                ops.BACKUPS = previous


if __name__ == '__main__':
    unittest.main()
