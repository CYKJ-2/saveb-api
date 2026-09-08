<?php

namespace App\Models;

/**
 * Invoice 商品明细模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property int $invoice_id 所属 Invoice，FK → invoice_orders.id（级联）
 * @property string|null $product_name 商品名称
 * @property string|null $description 商品说明
 * @property int $quantity 数量
 * @property string|null $price 单价/行金额
 * @property string|null $notes 备注
 * @property int|null $image_attachment_id 商品图片附件 ID
 * @property string $entity_uuid 新域稳定 UUID，唯一
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class InvoiceItem extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'invoice_items';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];
}
