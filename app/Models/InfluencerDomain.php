<?php

namespace App\Models;

/**
 * 达人网站归属模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property string $domain 来源域名，唯一
 * @property string|null $influencer_name Influencer 名称（**保留 ERP 原貌，不引入 influencer_id**）
 * @property bool $confirmed 是否人工确认
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class InfluencerDomain extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'influencer_domains';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /**
     * 定义字段类型转换。
     */
    protected function casts(): array
    {
        return ['confirmed' => 'boolean'];
    }
}
