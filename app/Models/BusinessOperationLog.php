<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 业务操作日志模型：定义数据表、字段转换及关联关系。
 */
class BusinessOperationLog extends Model
{
    /** @var string 数据表名 */
    protected $table = 'business_operation_logs';

    protected $connection = 'pgsql';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /**
     * 定义字段类型转换。
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }
}
