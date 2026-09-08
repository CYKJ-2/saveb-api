<?php

namespace App\Models;

/**
 * 网站分类调整记录，对应 site_classification_reclassifications 表。
 *
 * @property string $release_id 发布/修复批次标识，PK 组成
 * @property string $order_id ERP 订单标识，PK 组成
 * @property string $source_domain 来源域名
 * @property string $previous_classification 重分类前归类
 * @property string|null $previous_influencer_name 重分类前 Influencer
 * @property string $target_classification 重分类后归类
 * @property string|null $target_influencer_name 重分类后 Influencer
 * @property \Carbon\CarbonInterface $created_at 重分类记录时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class SiteClassificationReclassification extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'site_classification_reclassifications';

    protected $primaryKey = 'release_id';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /** 使用完整复合键限定更新、删除与 refresh；查询时同时传入 release_id 和 order_id。 */
    protected function setKeysForSaveQuery($query)
    {
        foreach (['release_id', 'order_id'] as $column) {
            $value = $this->getOriginal($column) ?? $this->getAttribute($column);
            if ($value === null) {
                throw new \LogicException('复合主键字段不可为空：' . $column);
            }
            $query->where($column, $value);
        }

        return $query;
    }

    protected function setKeysForSelectQuery($query)
    {
        return $this->setKeysForSaveQuery($query);
    }
}
