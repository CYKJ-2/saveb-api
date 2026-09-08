<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * 工作流事件，对应 workflow_events 表。
 *
 * @property string $id 主键
 * @property string $entity_type 实体类型
 * @property string $entity_uuid 实体 UUID
 * @property string $event_type 事件类型
 * @property string|null $from_state 迁移前状态
 * @property string|null $to_state 迁移后状态
 * @property string $actor_user_uuid FK → users.entity_uuid
 * @property string|null $request_id 请求追踪标识
 * @property string $source 事件来源
 * @property array|null $before 事件前快照
 * @property array|null $after 事件后快照
 * @property \Carbon\CarbonInterface $created_at 事件时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class WorkflowEvent extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'workflow_events';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
