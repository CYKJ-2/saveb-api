<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * 采购任务商品明细，对应 purchase_task_items 表。
 *
 * @property string $id 主键
 * @property string $purchase_task_id FK → purchase_tasks.id（级联）
 * @property string|null $order_item_id FK → order_items.id
 * @property string|null $sku SKU
 * @property string $product_name 商品名称
 * @property int $quantity 数量
 * @property string|null $notes 备注
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class PurchaseTaskItem extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'purchase_task_items';

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
