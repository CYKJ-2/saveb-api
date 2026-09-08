<?php

namespace App\Models;

/**
 * 订单客服分摊模型：定义数据表、字段转换及关联关系。
 *
 * @property string $id 主键
 * @property string $order_override_id 所属覆盖记录，FK → order_user_overrides.id（级联）
 * @property string $staff_code 员工编码
 * @property string $participant_role primary / collaborator（CHECK）
 * @property string $share_ratio 绩效分摊比例，CHECK ∈ (0, 1]
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class OrderStaffAllocation extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'order_staff_allocations';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];
}
