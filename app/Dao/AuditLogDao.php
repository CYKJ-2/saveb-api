<?php

namespace App\Dao;

use App\Models\AuditLog;

/**
 * 审计日志数据访问：封装模型查询与持久化操作。
 */
class AuditLogDao extends BaseDao
{
    protected function model(): string
    {
        return AuditLog::class;
    }
}
