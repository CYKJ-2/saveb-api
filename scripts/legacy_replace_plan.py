"""服务器替换迁移的明确清单：不使用 CASCADE，也不推断未知表可以删除。"""
from legacy_import_preflight import BUSINESS, PROTECTED

TARGET_ONLY_BUSINESS = frozenset({
    'business_operation_logs', 'online_spreadsheets', 'analysis_imports',
    'analysis_categories', 'analysis_brands', 'analysis_suppliers',
    'analysis_supplier_rules', 'analysis_procurement_rows',
})
COLLECTOR_KEEP = frozenset({'schema_versions', 'schedules'})
COLLECTOR_RESET = frozenset({
    'exchange_rates', 'jobs', 'chunks', 'outbox', 'source_orders', 'coverage',
    'checkpoints', 'changes', 'scheduler_state', 'pending_state', 'pending_coverage',
    'reconciliation_reports', 'order_date_repairs', 'logistics_schedule',
})


def replacement_plan(tables):
    """旧系统没有的新业务表清空；RBAC、登录令牌、审计及迁移记录保留。"""
    reset, keep = [], []
    for item in tables:
        schema, table = item['schema'], item['table']
        if schema == 'public':
            if table in BUSINESS | TARGET_ONLY_BUSINESS:
                reset.append(item)
            elif table in PROTECTED:
                keep.append(item)
            else:
                raise ValueError('Unreviewed public table: ' + table)
        elif schema == 'collector':
            if table in COLLECTOR_RESET:
                reset.append(item)
            elif table in COLLECTOR_KEEP:
                keep.append(item)
            else:
                raise ValueError('Unreviewed collector table: ' + table)
        else:
            keep.append(item)
    if not BUSINESS <= {p['table'] for p in reset if p['schema'] == 'public'}:
        raise ValueError('Missing reviewed business tables.')
    if not PROTECTED <= {p['table'] for p in keep if p['schema'] == 'public'}:
        raise ValueError('Missing protected RBAC/framework tables.')
    return {'reset': sorted(reset, key=lambda p: (p['schema'], p['table'])),
            'keep': sorted(keep, key=lambda p: (p['schema'], p['table']))}


def validate_reset_tables(tables):
    allowed = {('public', t) for t in BUSINESS | TARGET_ONLY_BUSINESS}
    allowed |= {('collector', t) for t in COLLECTOR_RESET}
    selected = {(p['schema'], p['table']) for p in tables}
    if not {('public', t) for t in BUSINESS} <= selected or not selected <= allowed:
        raise ValueError('Unsafe replacement table selection.')


def startup_services(services):
    # 数据验收前不恢复 Collector worker/Beat，避免刚替换的快照立刻被采集更新。
    return [s for s in services if s['name'] in {
        'saveb-api-production-app-1', 'saveb-api-production-web-1',
        'saveb-collector-production-api-1',
    }]
