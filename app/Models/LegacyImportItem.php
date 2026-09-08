<?php

namespace App\Models;

/**
 * 历史数据导入明细，对应 legacy_import_items 表。
 *
 * @property int $id 主键
 * @property string $source_key 来源数据稳定键，唯一
 * @property string $source_sha256 来源内容摘要
 * @property string $entity_type 导入目标实体类型
 * @property string|null $entity_id 导入目标实体标识
 * @property \Carbon\CarbonInterface $imported_at 导入时间
 * @property \Carbon\CarbonInterface $created_at 创建时间（v4 新增）
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class LegacyImportItem extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'legacy_import_items';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'imported_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
