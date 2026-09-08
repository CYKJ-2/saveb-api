<?php

namespace App\Models;

/**
 * 订单状态观测，对应 order_status_observations 表。
 *
 * @property int $id 主键
 * @property string $order_uuid 正式订单，FK → orders.entity_uuid（级联）
 * @property string $identity_type 观测使用的身份类型
 * @property string $identity_key 观测使用的身份值
 * @property string $source_system 来源系统
 * @property string $order_source_stable_key 来源系统稳定订单键
 * @property string $status 上游原始状态
 * @property string $normalized_status 归一化状态
 * @property string|null $classification 观测时归类
 * @property \Carbon\CarbonInterface|null $source_business_time 上游业务时间
 * @property \Carbon\CarbonInterface $observed_at 系统观测时间
 * @property string $source 观测来源/触发路径
 * @property string|null $operation_uuid 对应完成操作，FK → pending_completion_operations.operation_uuid（置空），UNIQUE
 * @property array $bounded_projection 有界投影结果
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class OrderStatusObservation extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'order_status_observations';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'source_business_time' => 'datetime',
        'observed_at' => 'datetime',
        'bounded_projection' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
