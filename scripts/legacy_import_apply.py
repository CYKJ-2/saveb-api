#!/usr/bin/env python3
"""将已演练快照导入空业务库并迁移附件；默认只检查，--apply 才暂停新服务并写入。

不会重新导出旧库、清表、覆盖 RBAC 或修改旧附件卷。任何失败均保留材料；
暂停后失败会保持新服务停止，避免暴露未完成的数据。数据库与文件不是一个事务。
"""
import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import time
import uuid
from pathlib import Path

from legacy_import_preflight import BUSINESS
from legacy_import_rehearsal import (AUTH, Database, build_empty_database_import_sql,
    fingerprint_sql, historical_reference_counts, inventory, is_business_sequence,
    qi, qs, row_counts, save_json, sequence_inventory, sha256)


PROJECTS = {'saveb-api-production', 'saveb-collector-production'}


def docker(*args):
    result = subprocess.run(['docker', *args], capture_output=True, encoding='utf-8', timeout=300)
    if result.returncode:
        raise RuntimeError('Docker operation failed: ' + args[0])
    return result.stdout


def inspect(name):
    return json.loads(docker('inspect', name))[0]


def load_materials(root):
    checks = {}
    for line in (root / 'SHA256SUMS').read_text(encoding='utf-8').splitlines():
        digest, name = line.split('  ', 1)
        path = (root / name).resolve()
        if root.resolve() not in path.parents or path.is_symlink() or not re.fullmatch('[a-f0-9]{64}', digest):
            raise ValueError('Invalid checksum manifest path.')
        if sha256(path) != digest:
            raise ValueError('Material checksum mismatch: ' + name)
        checks[name] = digest
    required = {'source-business.dump', 'target-before.dump', 'source-structure.json',
                'target-structure.json', 'sequence-plan.json', 'expected-business.json', 'verification.json'}
    required |= {'copy-data/' + t + '.copy' for t in BUSINESS}
    if not required <= checks.keys():
        raise ValueError('Incomplete rehearsal materials.')
    state = json.loads((root / 'state.json').read_text(encoding='utf-8'))
    report = json.loads((root / 'verification.json').read_text(encoding='utf-8'))
    if state['status'] != 'database_rehearsal_passed' or report['status'] != 'database_rehearsal_passed':
        raise ValueError('Rehearsal has not passed.')
    if {t['table'] for t in report['business']} != BUSINESS or not all(t['match'] for t in report['business']):
        raise ValueError('Incomplete business verification.')
    return state


def same_structure(before, after):
    return all(before[key] == after[key] for key in ('tables', 'constraints', 'indexes', 'schemas'))


def attachment_volume(container):
    matches = [m for m in container['Mounts'] if m['Destination'] == '/data/attachments']
    if len(matches) != 1 or matches[0]['Type'] != 'volume':
        raise ValueError('Expected one named attachment volume.')
    return matches[0]['Name']


def attachment_run(image, old_volume, new_volume, output, mode):
    new_mount = 'type=volume,src=' + new_volume + ',dst=/new' + (',readonly' if mode == 'check' else '')
    # 使用服务器已运行 API 的镜像 ID；不访问 GHCR，不拉镜像，不执行应用入口。
    return docker('run', '--rm', '--pull', 'never', '--network', 'none', '--user', '0',
        '--entrypoint', 'php', '--mount', 'type=volume,src=' + old_volume + ',dst=/old,readonly',
        '--mount', new_mount, '--mount', 'type=bind,src=' + str(output) + ',dst=/work', image,
        '/work/attachments.php', mode, '/work/attachments-manifest.json', '/work/attachments-' + mode + '.json')


def new_services():
    result = []
    for project in sorted(PROJECTS):
        for cid in docker('ps', '-q', '--filter', 'label=com.docker.compose.project=' + project).split():
            item = inspect(cid)
            if item['Config']['Labels'].get('com.docker.compose.project') != project:
                raise ValueError('Unexpected service project.')
            result.append({'id': item['Id'], 'name': item['Name'].lstrip('/'), 'project': project})
    return result


def resume_services(services):
    if not services:
        return
    docker('start', *[s['id'] for s in services])
    end = time.monotonic() + 180
    while time.monotonic() < end:
        states = [inspect(s['id'])['State'] for s in services]
        if all(s['Running'] and s.get('Health', {}).get('Status', 'healthy') == 'healthy' for s in states):
            return
        time.sleep(2)
    raise RuntimeError('Containers started but health checks did not pass; inspect the new API/Collector logs.')


def commit_sql(db, sql, output):
    # 此处是唯一正式数据库写入口；调用前必须完成 --apply、目标身份、空库、备份和附件检查。
    script = AUTH + 'db_name="$1"\nexec psql -X -qAt -v ON_ERROR_STOP=1 -U "$db_user" -d "$db_name"'
    with (output / 'private-errors.log').open('ab') as errors:
        result = subprocess.run(['docker', 'exec', '-i', db.container, 'sh', '-c', script, 'apply', db.database],
            input=sql.encode('utf-8'), stdout=subprocess.DEVNULL, stderr=errors, timeout=3600)
    if result.returncode:
        raise RuntimeError('Import command failed; check database state before retrying (connection failures can make commit status uncertain).')


def apply_snapshot(args, output):
    root = args.snapshot.resolve()
    state = load_materials(root)
    if state['target_database'] != args.expected_database or state['target_container'] != args.target_container:
        raise ValueError('Target differs from the successful rehearsal.')
    if state['source_container'] == args.target_container:
        raise ValueError('Refusing to import into the source container.')
    db = Database(args.target_container, output / 'private-errors.log', args.expected_database)
    source = json.loads((root / 'source-structure.json').read_text(encoding='utf-8'))
    target = json.loads((root / 'target-structure.json').read_text(encoding='utf-8'))
    expected = json.loads((root / 'expected-business.json').read_text(encoding='utf-8'))
    current = db.metadata()
    if not same_structure(target, current):
        raise ValueError('Target structure changed since rehearsal; repeat rehearsal against the new structure.')
    current_counts = row_counts(db, current)
    if any(current_counts[n] for n in BUSINESS):
        raise ValueError('Target already has business data; refusing full import. Do not clear it to bypass this check.')
    source_api, target_api = inspect(args.source_api_container), inspect(args.target_api_container)
    source_db_info = inspect(state['source_container'])
    old_project = source_db_info['Config']['Labels'].get('com.docker.compose.project')
    if not old_project or old_project in PROJECTS or source_api['Config']['Labels'].get('com.docker.compose.project') != old_project:
        raise ValueError('Source API is not in the source database project.')
    if target_api['Config']['Labels'].get('com.docker.compose.project') != 'saveb-api-production':
        raise ValueError('Target API project mismatch.')
    old_volume, new_volume = attachment_volume(source_api), attachment_volume(target_api)
    if old_volume == new_volume:
        raise ValueError('Source and target attachment volumes must differ.')
    if not re.fullmatch(r'saveb_rehearsal_src_[a-f0-9]{12}', state['staging_database']):
        raise ValueError('Invalid staging database.')
    staging = Database(args.target_container, output / 'private-errors.log', state['staging_database'])
    fields = [c['name'] for t in source['tables'] if t['name'] == 'attachments' for c in t['columns']]
    if staging.sql(fingerprint_sql('public', 'attachments', fields) + ';')[0] != expected['attachments']:
        raise ValueError('Attachment snapshot changed; refusing copy.')
    manifest = staging.sql("SELECT json_build_object('file_path',file_path,'sha256',sha256) FROM public.attachments ORDER BY id;")
    save_json(output / 'attachments-manifest.json', manifest)
    shutil.copyfile(Path(__file__).with_name('legacy_import_attachments.php'), output / 'attachments.php')
    print('检查旧附件和目标同路径文件，暂不写入。', flush=True)
    try:
        attachment_run(target_api['Image'], old_volume, new_volume, output, 'check')
    except RuntimeError:
        raise RuntimeError('Attachment check failed; see attachments-check.json. Services were not stopped.') from None
    status = {'status': 'checked', 'database_committed': False, 'snapshot': str(root),
              'target_container': db.container, 'target_database': db.database,
              'old_attachment_volume': old_volume, 'new_attachment_volume': new_volume,
              'stopped_services': []}
    save_json(output / 'apply-state.json', status)
    if not args.apply:
        print('正式导入前检查通过；没有停止服务、复制附件或写入正式库。使用相同参数加 --apply 执行。')
        return
    services = new_services()
    if not any(s['name'] == args.target_api_container for s in services):
        raise ValueError('Target API must be running before this managed import.')
    status.update(status='stopping_new_services', stopped_services=services)
    save_json(output / 'apply-state.json', status)
    print('仅暂停新 API/Collector 服务，旧后台继续运行。', flush=True)
    docker('stop', '--time', '90', *[s['id'] for s in services])
    if new_services():
        raise ValueError('New-system services are still running; keep them stopped before import.')
    # 暂停后重新核对；保留最新 RBAC 和令牌，不能拿演练时的 RBAC 覆盖现在的值。
    current = db.metadata()
    current_counts = row_counts(db, current)
    if not same_structure(target, current) or any(current_counts[n] for n in BUSINESS):
        raise ValueError('Target changed before the import window.')
    print('备份当前新库与新附件卷。', flush=True)
    db.dump(output / 'target-immediately-before.dump')
    docker('run', '--rm', '--pull', 'never', '--network', 'none', '--entrypoint', 'tar',
        '--mount', 'type=volume,src=' + new_volume + ',dst=/new,readonly',
        '--mount', 'type=bind,src=' + str(output) + ',dst=/work', target_api['Image'],
        '-czf', '/work/attachments-before.tar.gz', '-C', '/new', '.')
    docker('run', '--rm', '--pull', 'never', '--network', 'none', '--entrypoint', 'sh',
        '--mount', 'type=bind,src=' + str(output) + ',dst=/work,readonly', target_api['Image'],
        '-c', 'tar -tzf /work/attachments-before.tar.gz > /dev/null')
    (output / 'BACKUP-SHA256SUMS').write_text(''.join(sha256(output/n)+'  '+n+'\n' for n in
        ['target-immediately-before.dump','attachments-before.tar.gz']), encoding='utf-8')
    protected = [dict(p, fingerprint=db.sql(fingerprint_sql(p['schema'], p['table']) + ';')[0])
                 for p in inventory(db) if p['schema'] != 'public' or p['table'] not in BUSINESS]
    original_sequences = sequence_inventory(db)
    sequences = json.loads((root / 'sequence-plan.json').read_text(encoding='utf-8'))
    for plan in sequences:
        item = next(s for s in original_sequences if (s['schema'], s['name']) == (plan['schema'], plan['sequence']))
        if not is_business_sequence(item) or item['increment'] != 1:
            raise ValueError('Business sequence dependencies changed.')
        plan['next'] = max(plan['next'], item['last'] + int(item['called']))
        if plan['next'] > item['max']:
            raise ValueError('Business sequence exhausted.')
    save_json(output / 'protected-before.json', protected)
    save_json(output / 'sequences-before.json', original_sequences)
    print('按快照 SHA256 复制附件；已有同内容文件保留，冲突不覆盖。', flush=True)
    attachment_run(target_api['Image'], old_volume, new_volume, output, 'copy')
    status['status'] = 'attachments_copied'
    save_json(output / 'apply-state.json', status)
    data_dir = '/tmp/saveb-formal-import-' + uuid.uuid4().hex[:12]
    docker('cp', str(root / 'copy-data'), db.container + ':' + data_dir)
    sql = build_empty_database_import_sql(db.database, source, current, expected, protected, sequences, data_dir)
    # 校验材料复制到容器后的字节，避免复制损坏后仍执行。
    for name in BUSINESS:
        digest = docker('exec', db.container, 'sha256sum', data_dir + '/' + name + '.copy').split()[0]
        if digest != sha256(root / 'copy-data' / (name + '.copy')):
            raise ValueError('Container COPY data checksum mismatch.')
    (output / 'formal-import.sql').write_text(sql, encoding='utf-8')
    status['status'] = 'database_commit_started'
    status['database_committed'] = None  # 连接在提交时断开，结果可能不确定；不能将 false 当作未写入证明。
    save_json(output / 'apply-state.json', status)
    print('向已确认的新库执行单事务导入和内容校验。', flush=True)
    commit_sql(db, sql, output)
    status.update(status='database_committed', database_committed=True)
    save_json(output / 'apply-state.json', status)
    actual_sequences = sequence_inventory(db)
    if [s for s in original_sequences if not is_business_sequence(s)] != [s for s in actual_sequences if not is_business_sequence(s)]:
        raise ValueError('Protected sequence changed; keep services stopped for inspection.')
    for plan in sequences:
        item = next(s for s in actual_sequences if (s['schema'], s['name']) == (plan['schema'], plan['sequence']))
        if item['last'] != plan['next'] or item['called']:
            raise ValueError('Business sequence validation failed.')
    references = historical_reference_counts(db)
    save_json(output / 'verification.json', {'business_tables': len(BUSINESS), 'rows': sum(v['rows'] for v in expected.values()),
        'protected_tables_unchanged': len(protected), 'historical_user_references': references,
        'business_sequences_verified': len(sequences), 'attachments': json.loads((output/'attachments-copy.json').read_text(encoding='utf-8'))})
    print('导入校验通过，恢复本次暂停的服务并等待健康检查。', flush=True)
    resume_services(services)
    status['status'] = 'complete'
    save_json(output / 'apply-state.json', status)
    print('正式导入完成：38 张业务表；RBAC 保留；附件已校验；新服务已恢复。材料目录: ' + str(output))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--snapshot', required=True, type=Path)
    parser.add_argument('--target-container', default='saveb-infra-postgres-1')
    parser.add_argument('--expected-database', default='saveb')
    parser.add_argument('--source-api-container', default='saveb-erp-phase4-task2-a823095-20260718-190342-api-1')
    parser.add_argument('--target-api-container', default='saveb-api-production-app-1')
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=False)
    try:
        apply_snapshot(args, output)
    except Exception as error:
        save_json(output / 'failure.json', {'reason': str(error), 'instruction': 'Inspect apply-state.json; if services were stopped, they are not automatically resumed on failure.'})
        print('已停止后续步骤: ' + str(error) + '\n材料目录: ' + str(output)
              + '\n若已暂停新服务，失败后保持停止；检查 apply-state.json 判断数据库是否已提交，不要直接重复导入。', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
