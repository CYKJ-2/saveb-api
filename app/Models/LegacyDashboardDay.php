<?php

namespace App\Models;

/**
 * 历史首页日汇总，对应 legacy_dashboard_days 表。
 *
 * @property \Carbon\CarbonInterface $day 快照业务日，主键
 * @property array $payload 旧版 Dashboard 日数据
 * @property string $source_sha256 来源文件 SHA-256
 * @property int $source_size_bytes 来源文件字节数
 * @property string $snapshot_cutoff_asia_shanghai Asia/Shanghai 快照截止时间描述
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class LegacyDashboardDay extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'legacy_dashboard_days';

    protected $primaryKey = 'day';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'day' => 'date',
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
