<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * 达人订单关联，对应 influencer_order_links 表。
 *
 * @property string $id 主键
 * @property string $influencer_id FK → influencers.id（级联）
 * @property string $order_uuid FK → orders.entity_uuid（级联）
 * @property string $source 关联来源
 * @property string|null $created_by_user_uuid 创建用户，FK → users.entity_uuid
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class InfluencerOrderLink extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'influencer_order_links';

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
}
