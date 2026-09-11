"""正式入口的材料完整性和服务范围回归。"""
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from legacy_import_apply import BUSINESS, PROJECTS, attachment_volume, load_materials, new_services
from legacy_import_rehearsal import sha256


class ApplyTests(unittest.TestCase):
    def test_modified_copy_data_cannot_pass_material_validation(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'copy-data').mkdir()
            required = ['source-business.dump', 'target-before.dump', 'source-structure.json',
                        'target-structure.json', 'sequence-plan.json', 'expected-business.json']
            required += ['copy-data/'+name+'.copy' for name in BUSINESS]
            for name in required:
                (root/name).write_text('fixture')
            (root/'verification.json').write_text(json.dumps({'status': 'database_rehearsal_passed',
                'business': [{'table': name, 'match': True} for name in BUSINESS]}))
            required.append('verification.json')
            (root/'state.json').write_text(json.dumps({'status': 'database_rehearsal_passed'}))
            (root/'SHA256SUMS').write_text(''.join(sha256(root/n)+'  '+n+'\n' for n in required))
            self.assertEqual(load_materials(root)['status'], 'database_rehearsal_passed')
            (root/'copy-data/orders.copy').write_text('altered')
            with self.assertRaisesRegex(ValueError, 'checksum mismatch'):
                load_materials(root)

    def test_only_new_projects_are_selected_for_pause(self):
        def result(*args):
            self.assertEqual(args[:2], ('ps', '-q'))
            project = args[-1].rsplit('=', 1)[1]
            self.assertIn(project, PROJECTS)
            return 'fixture-id' if project == 'saveb-api-production' else ''
        info = {'Id': 'fixture-id', 'Name': '/new-api', 'Config': {'Labels': {'com.docker.compose.project': 'saveb-api-production'}}}
        with patch('legacy_import_apply.docker', side_effect=result), patch('legacy_import_apply.inspect', return_value=info):
            self.assertEqual([s['name'] for s in new_services()], ['new-api'])
        info['Config']['Labels']['com.docker.compose.project'] = 'old-erp'
        with patch('legacy_import_apply.docker', side_effect=result), patch('legacy_import_apply.inspect', return_value=info):
            with self.assertRaises(ValueError):
                new_services()

    def test_unknown_attachment_mount_is_refused(self):
        with self.assertRaises(ValueError):
            attachment_volume({'Mounts': []})
        with self.assertRaises(ValueError):
            attachment_volume({'Mounts': [{'Destination': '/data/attachments', 'Type': 'bind'}]})
        self.assertEqual(attachment_volume({'Mounts': [{'Destination': '/data/attachments', 'Type': 'volume', 'Name': 'fixture'}]}), 'fixture')


if __name__ == '__main__':
    unittest.main()
