<?php

namespace App\Dao;

use App\Models\AuditLog;

/**
 * 审计日志数据访问：封装模型查询与持久化操作。
 */
class AuditLogDao extends BaseDao
{
    /**
     * 指定当前 DAO 使用的 Eloquent 模型类。
     *
     * @return class-string<AuditLog> 模型类名
     */
    protected function model(): string
    {
        return AuditLog::class;
    }
}
