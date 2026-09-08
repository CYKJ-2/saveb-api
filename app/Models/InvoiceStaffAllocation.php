<?php

namespace App\Models;

/**
 * Invoice 客服分摊模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property int $invoice_id 所属 Invoice，FK → invoice_orders.id（级联）
 * @property string $staff_code 员工编码
 * @property string $commission_percent 佣金百分比
 * @property string $share_ratio 分摊比例
 * @property \Carbon\CarbonInterface $created_at 创建时间（v4 新增）
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class InvoiceStaffAllocation extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'invoice_staff_allocations';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];
}
