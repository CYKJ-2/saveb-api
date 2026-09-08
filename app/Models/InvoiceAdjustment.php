<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Invoice 调整记录，对应 invoice_adjustments 表。
 *
 * @property string $id 主键
 * @property string $invoice_uuid 所属 Invoice，FK → invoice_orders.entity_uuid（级联）
 * @property string $adjustment_type discount / credit / shipping / no_box / other（CHECK）
 * @property string|null $amount 固定调整金额
 * @property string|null $percentage 百分比调整
 * @property string|null $reason 调整原因
 * @property string|null $created_by_user_uuid 创建用户，FK → users.entity_uuid
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class InvoiceAdjustment extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'invoice_adjustments';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
