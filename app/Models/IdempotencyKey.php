<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * 请求幂等记录，对应 idempotency_keys 表。
 *
 * @property string $id 主键
 * @property string $actor_user_uuid FK → users.entity_uuid
 * @property string $scope 幂等作用域（UQ 组成）
 * @property string $idempotency_key 客户端幂等键（UQ 组成）
 * @property string $request_hash 请求内容摘要
 * @property string $state processing / completed（CHECK）
 * @property int|null $response_status 已完成请求的 HTTP 状态
 * @property array|null $response_body 已完成请求的响应快照
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $expires_at 过期时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class IdempotencyKey extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'idempotency_keys';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'response_body' => 'array',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
