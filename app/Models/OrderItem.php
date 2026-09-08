<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * 订单商品明细，对应 order_items 表。
 *
 * @property string $id 主键
 * @property string $order_uuid 所属订单，FK → orders.entity_uuid（级联删除）
 * @property string|null $sku 商品 SKU
 * @property string $product_name 商品名称
 * @property int $quantity 数量
 * @property string|null $unit_price 单价
 * @property string|null $currency 单价币种
 * @property array $metadata 商品扩展属性
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v3 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v3 新增）
 */
class OrderItem extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'order_items';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
