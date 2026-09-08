<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 采购任务模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 历史采购任务主键
 * @property int|null $order_pk FK → orders.id
 * @property string|null $order_id 订单标识快照
 * @property string $purchase_status 状态，CHECK ∈ 历史六态
 * @property string|null $supplier 供应商
 * @property string|null $cost 采购成本
 * @property string|null $eta 预计到达日
 * @property string|null $tracking_no 物流单号
 * @property string|null $notes 采购备注
 * @property int|null $created_by 创建用户（bigint）
 * @property string|null $legacy_id 旧系统稳定标识，唯一
 * @property array|null $raw 旧系统原始快照
 * @property string $entity_uuid 新域稳定 UUID，唯一
 * @property int $version 乐观锁版本
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 * @property-read \App\Models\WarehouseRecord|null $warehouse
 */
class ProcurementTask extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'procurement_tasks';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /**
     * 定义字段类型转换。
     */
    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'cost' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    /**
     * 关联仓库记录。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<\App\Models\WarehouseRecord, $this>
     */
    public function warehouse(): HasOne
    {
        return $this->hasOne(WarehouseRecord::class);
    }
}
