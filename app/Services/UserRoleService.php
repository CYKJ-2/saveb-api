<?php

namespace App\Services;

use App\Dao\RoleDao;
use App\Dao\UserRoleDao;
use App\Exceptions\SystemException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 用户角色授权服务：处理业务规则、统计口径和事务。
 */
class UserRoleService
{
    /**
     * 注入 用户角色处理所需的依赖。
     *
     * @param  UserRoleDao  $userRoleDao  用户角色数据访问对象
     * @param  RoleDao  $roleDao  角色数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private readonly UserRoleDao $userRoleDao, private readonly RoleDao $roleDao)
    {
    }

    /**
     * 校验授权范围和当前管理员身份，再在事务中同步用户角色。
     *
     * @param  User  $user  用户模型
     * @param  array  $ids  角色主键 ID 列表
     * @return void 无返回值；副作用见方法说明
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 指定业务记录不存在
     * @see RoleDao::findWithRelations()
     * @see UserRoleDao::sync()
     */
    public function sync(User $user, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $actor = request()->attributes->get('auth_user');
        $codes = $actor ? app(RbacService::class)->codes($actor) : [];
        if ($actor && $actor->id === $user->id && in_array('*', $codes, true)) {
            $retainsSuperAdmin = $this->roleDao
                ->getAll(['id' => $ids])
                ->contains(fn ($role) => $role->code === 'super_admin' && $role->status === 1);
            if (!$retainsSuperAdmin) {
                throw new SystemException(-1000, '不能移除当前登录账户的超级管理员角色。', 422);
            }
        }
        foreach ($ids as $id) {
            $role = $this->roleDao->findWithRelations($id);
            if (!$role) {
                throw new SystemException(-1000, '角色不存在。', 422);
            }
            if ($actor && !in_array('*', $codes, true) && ($role->code === 'super_admin' || array_diff($role->permissions
                ->pluck('code')
                ->all(), $codes))) {
                throw new SystemException(-1000, '不能授予超出自身范围的角色。', 403);
            }
        }
        DB::connection('pgsql')
            ->transaction(function () use ($user, $ids, $actor) {
                $locked = User::query()
                    ->lockForUpdate()
                    ->findOrFail($user->id);
                $this->userRoleDao->sync($locked, $ids, $actor?->id);
            });
    }
}
