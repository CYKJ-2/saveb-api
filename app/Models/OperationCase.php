<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * 业务处理工单，对应 operation_cases 表。
 *
 * @property string $id 主键
 * @property string $case_type Case 类型
 * @property string $status 当前状态
 * @property string|null $entity_type 关联实体类型
 * @property string|null $entity_uuid 关联实体 UUID
 * @property string $summary 摘要
 * @property array $details 结构化详情
 * @property int $version 乐观锁版本
 * @property string|null $owner_user_uuid 当前负责人，FK → users.entity_uuid
 * @property string $created_by_user_uuid 创建用户，FK → users.entity_uuid
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class OperationCase extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'operation_cases';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
