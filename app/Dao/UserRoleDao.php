<?php

namespace App\Dao;

use App\Models\User;

/**
 * 用户角色授权数据访问：封装模型查询与持久化操作。
 */
class UserRoleDao
{
    /**
     * 同步角色关联和授权人，同时保留仍有效的兼容主角色。
     */
    public function sync(
        User $user,
        array $ids,
        ?int $actorId,
    ): void {
        $pivot = [];
        foreach ($ids as $id) {
            $pivot[$id] = ['granted_by_user_id' => $actorId];
        }
        $user
            ->roles()
            ->sync($pivot);
        // role_id is a compatibility primary role within the complete assignment.
        $user->role_id = in_array((int) $user->role_id, $ids, true) ? $user->role_id : $ids[0] ?? null;
        $user->save();
    }
}
