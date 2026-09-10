<?php

namespace App\Models;

/**
 * 达人模型：定义数据表、字段转换及关联关系。
 *
 * @property string $id 主键
 * @property string $display_name 显示名称
 * @property string $status 当前状态
 * @property array $profile 扩展资料
 * @property int $version 乐观锁版本
 * @property string|null $created_by_user_uuid 创建用户，FK → users.entity_uuid
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class Influencer extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'influencers';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /**
     * 定义字段类型转换。
     *
     * @return array<string, string> 数据库字段名到 Eloquent 转换类型的映射
     */
    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'version' => 'integer',
        ];
    }
}
