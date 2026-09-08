<?php

namespace App\Services;

use App\Dao\PermissionDao;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 统一计算登录、当前用户信息和 API 鉴权所使用的有效权限。
 */
class RbacService
{
    public function __construct(private readonly PermissionDao $permissionDao)
    {
    }

    /**
     * 计算有效权限：祖先节点必须启用，导航仅补齐祖先菜单，不扩展同级操作权限。
     */
    public function nodes(User $user): Collection
    {
        $user->loadMissing(['role.permissions', 'roles.permissions']);
        $all = $this->permissionDao
            ->listAll()
            ->keyBy('id');
        $roles = $user->effectiveRoles();
        $granted = $roles->contains('code', 'super_admin') ? $all : $roles
            ->flatMap(fn ($role) => $role->permissions)
            ->keyBy('id');
        $result = collect();
        foreach ($granted as $node) {
            $chain = collect();
            $current = $node;
            while ($current) {
                if ((int) $current->status !== 1 || $chain->has($current->id)) {
                    continue 2;
                }
                $chain->put($current->id, $current);
                if ((int) $current->parent_id === 0) {
                    break;
                }
                $current = $all->get($current->parent_id);
                if (!$current) {
                    continue 2;
                }
            }
            // 只补齐导航所需的祖先菜单，同级操作权限仍需单独授权。
            foreach ($chain as $item) {
                if ($item->id === $node->id || $item->type === 'menu') {
                    $result->put($item->id, $item);
                }
            }
        }

        return $result->values();
    }

    /**
     * 提取有效权限编码；仅超级管理员返回通配权限。
     */
    public function codes(User $user): array
    {
        $user->loadMissing(['role.permissions', 'roles.permissions']);

        return in_array('super_admin', $user->effectiveRoleCodes(), true) ? ['*'] : $this
            ->nodes($user)
            ->pluck('code')
            ->reject(fn ($code) => $code === '*')
            ->values()
            ->all();
    }
}
