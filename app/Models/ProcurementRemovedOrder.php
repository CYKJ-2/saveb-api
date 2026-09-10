<?php

namespace App\Models;

/**
 * 已移除采购来源模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property string $legacy_id 旧系统稳定标识，唯一
 * @property string|null $order_id ERP 订单标识
 * @property string|null $paypal_order_id PayPal 订单标识
 * @property array $raw 移除时快照
 * @property int|null $removed_by 执行移除用户（bigint）
 * @property string $removed_at 移除时间
 * @property \Carbon\CarbonInterface $created_at 创建时间（v4 新增）
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class ProcurementRemovedOrder extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'procurement_removed_orders';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /**
     * 定义字段类型转换。
     *
     * @return array<string, string> 数据库字段名到 Eloquent 转换类型的映射
     */
    protected function casts(): array
    {
        return ['raw' => 'array'];
    }
}
