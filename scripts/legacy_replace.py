#!/usr/bin/env python3
"""在服务器执行旧业务快照替换；prepare 只演练，apply 默认检查，加 --apply 才正式写入。"""
import argparse
import os
import sys
from pathlib import Path

from legacy_import_apply import apply_snapshot, inspect
from legacy_import_rehearsal import Database, rehearse, save_json


def validate_server(args):
    if args.target_container != 'saveb-infra-postgres-1' or args.expected_database != 'saveb':
        raise ValueError('Replacement is restricted to the confirmed server target saveb-infra-postgres-1/saveb.')
    target = inspect(args.target_container)
    if target['Config']['Labels'].get('com.docker.compose.project') != 'saveb-infra':
        raise ValueError('Target is not the confirmed server infra project.')
    for path in [args.output] + ([args.snapshot] if args.command == 'apply' else []):
        # 只允许服务器已约定工作目录中的新材料目录，不接受本地开发或旧项目目录。
        if Path('/home/admin_chen/www/backups') not in path.resolve().parents:
            raise ValueError('Materials must be under /home/admin_chen/www/backups.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    for name in ('prepare', 'apply'):
        p = sub.add_parser(name)
        p.add_argument('--target-container', default='saveb-infra-postgres-1')
        p.add_argument('--expected-database', default='saveb')
        p.add_argument('--output', type=Path, required=True)
        if name == 'prepare':
            p.add_argument('--source-container', default='saveb-erp-phase4-task2-a823095-20260718-190342-postgres-1')
        else:
            p.add_argument('--snapshot', type=Path, required=True)
            p.add_argument('--source-api-container', default='saveb-erp-phase4-task2-a823095-20260718-190342-api-1')
            p.add_argument('--target-api-container', default='saveb-api-production-app-1')
            p.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    try:
        validate_server(args)
        args.output = args.output.resolve()
        args.output.mkdir(parents=True, exist_ok=False)
    except Exception as error:
        print('入口检查失败：' + str(error), file=sys.stderr)
        return 1
    try:
        if args.command == 'prepare':
            if args.source_container == args.target_container:
                raise ValueError('Source and target must differ.')
            log = args.output / 'private-errors.log'
            source = Database(args.source_container, log)
            target = Database(args.target_container, log, args.expected_database)
            if source.metadata()['database'] != 'phase4':
                raise ValueError('Expected the confirmed legacy phase4 database.')
            print('服务器替换演练：导出最新旧库快照；正式新库不写入。')
            rehearse(source, target, args.output, replace_business=True)
            print('查看 replacement-plan.json：reset 将被替换/清空，keep 保留。')
        else:
            apply_snapshot(args, args.output, replace_business=True)
    except Exception as error:
        save_json(args.output / 'failure.json', {'reason': str(error),
            'instruction': 'Check apply-state.json; services stay stopped after a failure during apply.'})
        print('替换流程停止：' + str(error) + '\n材料目录：' + str(args.output), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
