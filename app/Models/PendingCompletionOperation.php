<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * 待完成业务操作，对应 pending_completion_operations 表。
 *
 * @property string $operation_uuid 主键
 * @property string $identity_type client / order / paypal（CHECK）
 * @property string $identity_key 订单稳定身份值
 * @property string $order_uuid 目标正式订单，FK → orders.entity_uuid（级联）
 * @property \Carbon\CarbonInterface $business_date 业务归属日
 * @property string $source_status 操作前状态，CHECK = pending
 * @property string $target_status 操作后状态，CHECK = completed
 * @property string $target_classification payment_link（CHECK）
 * @property array $result 操作结果快照
 * @property \Carbon\CarbonInterface $completed_at 完成时间
 * @property \Carbon\CarbonInterface $created_at 记录创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class PendingCompletionOperation extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'pending_completion_operations';

    protected $primaryKey = 'operation_uuid';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'business_date' => 'date',
        'result' => 'array',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
