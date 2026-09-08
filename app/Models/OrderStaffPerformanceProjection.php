<?php

namespace App\Models;

/**
 * 订单客服绩效投影，对应 order_staff_performance_projection 表。
 *
 * @property int $id 主键
 * @property string $operation_uuid 来源操作，FK → pending_completion_operations（级联）
 * @property string $order_uuid 来源订单，FK → orders.entity_uuid（级联）
 * @property \Carbon\CarbonInterface $business_date 绩效归属日
 * @property string $staff_code 员工编码
 * @property string $share_ratio 员工份额，CHECK ∈ (0, 1]
 * @property string $orders_basis 订单数投影基数
 * @property string $items_basis 件数投影基数
 * @property string $amount_usd_basis 美元金额投影基数
 * @property string|null $commission_percent 佣金比例，CHECK ≥ 0
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class OrderStaffPerformanceProjection extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'order_staff_performance_projection';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'business_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
