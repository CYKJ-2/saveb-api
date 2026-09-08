<?php

namespace App\Models;

/**
 * 系统状态，对应 system_state 表。
 *
 * @property string $key 状态键，主键
 * @property array $value 状态值
 * @property \Carbon\CarbonInterface $updated_at 最近更新时间
 * @property \Carbon\CarbonInterface $created_at 创建时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class SystemState extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'system_state';

    protected $primaryKey = 'key';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'value' => 'array',
        'updated_at' => 'datetime',
        'created_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
