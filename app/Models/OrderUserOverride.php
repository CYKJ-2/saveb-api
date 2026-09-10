<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 订单人工调整模型：定义数据表、字段转换及关联关系。
 *
 * @property string $id 主键
 * @property string $order_key 被覆盖订单的稳定身份值
 * @property string $order_key_type 身份类型：client/order/paypal（CHECK）
 * @property string|null $order_uuid 解析后的正式订单，FK → orders.entity_uuid，删除订单时置空
 * @property string $source_status 覆盖前状态
 * @property string $status_override 覆盖后状态，CHECK = completed
 * @property string $primary_staff_code 主负责人编码
 * @property int $version 乐观锁版本
 * @property string $updated_by_user_uuid 最后修改用户，FK → users.entity_uuid
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderStaffAllocation> $allocations
 */
class OrderUserOverride extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'order_user_overrides';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /**
     * 关联客服分摊。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\OrderStaffAllocation, $this> 用于加载或继续约束该关联的 Eloquent 关系对象
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(OrderStaffAllocation::class, 'order_override_id');
    }
}
