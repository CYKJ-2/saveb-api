<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * 采购流程任务，对应 purchase_tasks 表。
 *
 * @property string $id 主键
 * @property int $task_number 可见任务编号，唯一
 * @property string|null $order_uuid FK → orders.entity_uuid
 * @property int|null $legacy_procurement_id FK → procurement_tasks.id
 * @property string $task_type order_purchase / replacement / exchange / other（CHECK）
 * @property string $source system / manual（CHECK）；system 必须有 order_uuid
 * @property string $status 状态，CHECK ∈ 十态
 * @property string|null $supplier 供应商
 * @property string|null $purchase_cost 采购成本
 * @property \Carbon\CarbonInterface|null $eta 预计到达日
 * @property string|null $tracking_number 物流单号
 * @property string|null $notes 备注
 * @property int $version 乐观锁版本
 * @property string $created_by_user_uuid FK → users.entity_uuid
 * @property string $updated_by_user_uuid FK → users.entity_uuid
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class PurchaseTask extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'purchase_tasks';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'eta' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
