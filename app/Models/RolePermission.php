<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * 角色权限关联模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property int $role_id 角色 ID；FK → roles.id，级联删除
 * @property int $permission_id 权限 ID（菜单或 action）；FK → permissions.id，级联删除
 * @property \Carbon\CarbonInterface $created_at
 * @property \Carbon\CarbonInterface $updated_at
 */
class RolePermission extends Pivot
{
    /** @var string 数据表名 */
    protected $table = 'role_permissions';

    /** @var bool 是否使用自增主键 */
    public $incrementing = true;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];
}
