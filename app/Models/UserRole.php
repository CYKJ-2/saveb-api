<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * 用户角色授权模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property int $user_id 用户 ID；FK → users.id，级联删除
 * @property int $role_id 角色 ID；FK → roles.id，级联删除
 * @property int|null $granted_by_user_id 授权人；FK → users.id，SET NULL
 * @property \Carbon\CarbonInterface $created_at
 * @property \Carbon\CarbonInterface $updated_at
 */
class UserRole extends Pivot
{
    /** @var string 数据表名 */
    protected $table = 'user_roles';

    /** @var bool 是否使用自增主键 */
    public $incrementing = true;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];
}
