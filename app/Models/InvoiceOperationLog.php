<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Invoice 操作日志，对应 invoice_operation_logs 表。
 *
 * @property string $id 主键
 * @property string $invoice_uuid Invoice，FK → invoice_orders.entity_uuid（级联）
 * @property string $action 操作动作
 * @property array|null $before 变更前快照
 * @property array|null $after 变更后快照
 * @property string $actor_user_uuid 操作者，FK → users.entity_uuid
 * @property string|null $request_id 请求追踪标识
 * @property string $source 操作来源
 * @property \Carbon\CarbonInterface $created_at 操作时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增；审计表通常保留但保留入口）
 */
class InvoiceOperationLog extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'invoice_operation_logs';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
