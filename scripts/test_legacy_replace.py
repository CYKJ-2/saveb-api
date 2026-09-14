"""替换白名单、SQL 原子性、旧入口隔离；可选临时 PostgreSQL 容器演练。"""
import copy
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch
from uuid import uuid4

from legacy_import_preflight import BUSINESS, PROTECTED
from legacy_import_rehearsal import build_empty_database_import_sql
from legacy_replace_plan import (COLLECTOR_KEEP, COLLECTOR_RESET, TARGET_ONLY_BUSINESS,
                                 replacement_plan, startup_services, validate_reset_tables)


def tables():
    return ([{'schema': 'public', 'table': t} for t in BUSINESS | PROTECTED | TARGET_ONLY_BUSINESS]
            + [{'schema': 'collector', 'table': t} for t in COLLECTOR_KEEP | COLLECTOR_RESET])


def fixture_sql(database='fixture', bad=False):
    source = {'tables': [{'name': t, 'columns': [{'name': 'id', 'type': 'integer',
        'not_null': True, 'default': None}]} for t in sorted(BUSINESS)]}
    target = copy.deepcopy(source)
    target['constraints'] = []
    expected = {t: {'rows': 1, 'checksum': 'broken' if bad and t == 'orders'
                    else 'ac16163638075274ce9fdbebc9d7310e'} for t in BUSINESS}
    # 实际集成测试用数据库返回的内容指纹覆盖；单元测试只检查生成器边界。
    plan = replacement_plan(tables())
    protected = [dict(p, fingerprint={'rows': 0, 'checksum': 'd41d8cd98f00b204e9800998ecf8427e'})
                 for p in plan['keep']]
    return source, target, expected, protected, plan


class ReplacementTests(unittest.TestCase):
    def test_explicit_scope_and_unknown_table_refusal(self):
        plan = replacement_plan(tables())
        self.assertTrue(all(p in plan['keep'] for p in tables() if p['table'] in PROTECTED))
        self.assertIn({'schema': 'collector', 'table': 'schedules'}, plan['keep'])
        self.assertIn({'schema': 'collector', 'table': 'source_orders'}, plan['reset'])
        self.assertIn({'schema': 'public', 'table': 'online_spreadsheets'}, plan['reset'])
        for schema in ('public', 'collector'):
            with self.assertRaises(ValueError):
                replacement_plan(tables() + [{'schema': schema, 'table': 'unreviewed'}])
        with self.assertRaises(ValueError):
            validate_reset_tables(plan['reset'] + [{'schema': 'public', 'table': 'users'}])

    def test_sql_resets_and_verifies_in_one_transaction_without_cascade(self):
        src, dst, expected, protected, plan = fixture_sql()
        sql = build_empty_database_import_sql('fixture', src, dst, expected, protected, [], '/tmp/copy', plan['reset'])
        self.assertLess(sql.index('BEGIN;'), sql.index('TRUNCATE TABLE'))
        self.assertLess(sql.index('TRUNCATE TABLE'), sql.index('\\copy'))
        self.assertLess(sql.index('Protected table changed'), sql.index('COMMIT;'))
        truncate = next(line for line in sql.splitlines() if line.startswith('TRUNCATE'))
        self.assertNotIn('"users"', truncate)
        self.assertNotIn('"schedules"', truncate)
        self.assertIn('CONTINUE IDENTITY RESTRICT', truncate)
        self.assertNotIn('CASCADE', sql)
        old = build_empty_database_import_sql('fixture', src, dst, expected, protected, [], '/tmp/copy')
        self.assertNotIn('TRUNCATE', old)
        self.assertIn('Nonempty business table', old)

    def test_resume_only_new_interfaces(self):
        services = [{'name': n, 'project': 'saveb-api-production'} for n in (
            'saveb-api-production-app-1', 'saveb-api-production-web-1',
            'saveb-api-production-worker-1', 'saveb-collector-production-beat-1')]
        self.assertEqual([s['name'] for s in startup_services(services)],
                         ['saveb-api-production-app-1', 'saveb-api-production-web-1'])

    def test_wrapper_rejects_local_target_before_any_write(self):
        from argparse import Namespace
        from legacy_replace import validate_server
        with self.assertRaises(ValueError), patch('legacy_replace.inspect') as inspect:
            validate_server(Namespace(target_container='saveb-api-postgres', expected_database='test'))
        inspect.assert_not_called()


@unittest.skipUnless(os.environ.get('SAVEB_REPLACE_TEST_POSTGRES_IMAGE'), 'optional disposable PostgreSQL container')
class PostgreSQLReplacementTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.name = 'saveb-replace-test-' + uuid4().hex[:12]
        cls.cid = subprocess.check_output(['docker', 'run', '-d', '--rm', '--network', 'none',
            '--name', cls.name, '--tmpfs', '/var/lib/postgresql/data',
            '-e', 'POSTGRES_HOST_AUTH_METHOD=trust', '-e', 'POSTGRES_USER=postgres', '-e', 'POSTGRES_DB=postgres',
            '--pull', 'never', os.environ['SAVEB_REPLACE_TEST_POSTGRES_IMAGE']], text=True).strip()
        cls.addClassCleanup(subprocess.run, ['docker', 'rm', '-f', cls.cid], stdout=subprocess.DEVNULL)
        for _ in range(40):
            if subprocess.run(['docker', 'exec', cls.cid, 'pg_isready', '-U', 'postgres'],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
                break
            time.sleep(.25)
        else:
            raise RuntimeError('Disposable PostgreSQL did not start.')

    def psql(self, sql, success=True):
        result = subprocess.run(['docker', 'exec', '-i', self.cid, 'psql', '-X', '-qAt',
                                 '-U', 'postgres', '-d', 'postgres', '-v', 'ON_ERROR_STOP=1'],
                                input=sql, text=True, capture_output=True)
        self.assertEqual(result.returncode == 0, success, result.stderr)
        return result.stdout.strip()

    def setUp(self):
        self.psql('DROP SCHEMA IF EXISTS collector CASCADE; DROP SCHEMA public CASCADE; CREATE SCHEMA public; CREATE SCHEMA collector;')
        for p in tables():
            self.psql('CREATE TABLE "' + p['schema'] + '"."' + p['table'] + '" (id int PRIMARY KEY);')
        self.psql('INSERT INTO orders VALUES(9); INSERT INTO users VALUES(42); '
                  'INSERT INTO online_spreadsheets VALUES(8); INSERT INTO collector.source_orders VALUES(7); '
                  'INSERT INTO collector.jobs VALUES(6); INSERT INTO collector.schedules VALUES(30);')
        self.src, self.dst, self.expected, self.protected, self.plan = fixture_sql('postgres')
        from legacy_import_rehearsal import fingerprint_sql
        self.psql('CREATE TEMP TABLE unused(id int);')
        fingerprint = json.loads(self.psql("SELECT json_build_object('rows',1,'checksum',md5(md5('{\"id\":1}')))"))
        self.expected = {t: fingerprint for t in BUSINESS}
        for p in self.protected:
            p['fingerprint'] = json.loads(self.psql(fingerprint_sql(p['schema'], p['table'])))
        with tempfile.TemporaryDirectory() as directory:
            for t in BUSINESS:
                Path(directory, t + '.copy').write_text('1\n', encoding='utf-8')
            subprocess.run(['docker', 'cp', directory + '/.', self.cid + ':/tmp/'], check=True,
                           stdout=subprocess.DEVNULL)

    def test_replace_populated_tables_retains_rbac_and_schedule(self):
        sql = build_empty_database_import_sql('postgres', self.src, self.dst, self.expected,
              self.protected, [], '/tmp', self.plan['reset'])
        self.psql(sql)
        self.assertEqual(self.psql('SELECT id FROM orders'), '1')
        self.assertEqual(self.psql('SELECT id FROM users'), '42')
        self.assertEqual(self.psql('SELECT id FROM collector.schedules'), '30')
        self.assertEqual(self.psql('SELECT count(*) FROM collector.source_orders'), '0')
        self.assertEqual(self.psql('SELECT count(*) FROM online_spreadsheets'), '0')

    def test_content_failure_rolls_back_truncate_and_copy(self):
        self.expected['orders'] = {'rows': 123, 'checksum': 'wrong'}
        sql = build_empty_database_import_sql('postgres', self.src, self.dst, self.expected,
              self.protected, [], '/tmp', self.plan['reset'])
        self.psql(sql, success=False)
        self.assertEqual(self.psql('SELECT id FROM orders'), '9')
        self.assertEqual(self.psql('SELECT id FROM users'), '42')
        self.assertEqual(self.psql('SELECT id FROM collector.jobs'), '6')
        self.assertEqual(self.psql('SELECT id FROM online_spreadsheets'), '8')

    def test_protected_external_foreign_key_prevents_cascade(self):
        self.psql('ALTER TABLE users ADD CONSTRAINT protected_order_fk FOREIGN KEY(id) REFERENCES orders(id) NOT VALID;')
        sql = build_empty_database_import_sql('postgres', self.src, self.dst, self.expected,
              self.protected, [], '/tmp', self.plan['reset'])
        self.psql(sql, success=False)
        self.assertEqual(self.psql('SELECT id FROM orders'), '9')
        self.assertEqual(self.psql('SELECT id FROM users'), '42')

    def test_full_snapshot_rehearsal_leaves_live_fixture_unchanged(self):
        from legacy_import_rehearsal import Database, rehearse
        self.psql('CREATE DATABASE source_fixture;')
        data = '\n'.join('CREATE TABLE public."' + t + '" (id int PRIMARY KEY); '
                         'INSERT INTO public."' + t + '" VALUES(1);' for t in BUSINESS)
        subprocess.run(['docker', 'exec', '-i', self.cid, 'psql', '-X', '-q', '-U', 'postgres',
                        '-d', 'source_fixture', '-v', 'ON_ERROR_STOP=1'], input=data, text=True, check=True,
                       stdout=subprocess.DEVNULL)
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            source = Database(self.cid, output/'private-errors.log', 'source_fixture')
            target = Database(self.cid, output/'private-errors.log', 'postgres')
            report = rehearse(source, target, output, replace_business=True)
            self.assertEqual(report['rows'], 38)
            self.assertEqual(report['import_mode'], 'replace-business')
            self.assertTrue((output/'replacement-plan.json').exists())
            from legacy_import_apply import load_materials
            self.assertEqual(load_materials(output, 'replace-business')['import_mode'], 'replace-business')
            with self.assertRaises(ValueError):
                load_materials(output)
        self.assertEqual(self.psql('SELECT id FROM orders'), '9')
        self.assertEqual(self.psql('SELECT id FROM users'), '42')


@unittest.skipUnless(os.environ.get('SAVEB_REPLACE_TEST_PHP_IMAGE'), 'optional disposable PHP container')
class AttachmentReplacementTests(unittest.TestCase):
    def test_replace_and_original_no_overwrite_contracts(self):
        php = r'''<?php
mkdir('/old'); mkdir('/new'); mkdir('/work');
file_put_contents('/old/order.txt', 'legacy-latest');
file_put_contents('/new/order.txt', 'new-system-data');
$manifest = [['file_path'=>'order.txt', 'sha256'=>hash('sha256','legacy-latest')]];
file_put_contents('/work/manifest.json', json_encode($manifest));
function runMode($mode, $expected) {
    exec('php /helper.php '.escapeshellarg($mode).' /work/manifest.json /work/result.json', $out, $code);
    if ($code !== $expected) throw new RuntimeException($mode.' wrong exit code');
}
runMode('check', 1);
runMode('replace-check', 0);
if (file_get_contents('/new/order.txt') !== 'new-system-data') throw new RuntimeException('check wrote file');
runMode('replace-copy', 0);
if (file_get_contents('/new/order.txt') !== 'legacy-latest') throw new RuntimeException('replacement missing');
runMode('replace-copy', 0);
unlink('/new/order.txt'); symlink('/work/manifest.json', '/new/order.txt');
runMode('replace-copy', 1);
if (!is_link('/new/order.txt')) throw new RuntimeException('symlink protection failed');
echo 'attachment replacement tests passed';
'''
        helper = Path(__file__).with_name('legacy_import_attachments.php').resolve()
        result = subprocess.run(['docker', 'run', '--rm', '-i', '--pull', 'never', '--network', 'none',
            '--entrypoint', 'php', '--mount', 'type=bind,src='+str(helper)+',dst=/helper.php,readonly',
            os.environ['SAVEB_REPLACE_TEST_PHP_IMAGE']], input=php, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('attachment replacement tests passed', result.stdout)


if __name__ == '__main__':
    unittest.main()
