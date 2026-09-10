<?php

namespace App\Dao;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

/**
 * 角色数据访问对象。
 *
 * @extends BaseDao<Role>
 */
class RoleDao extends BaseDao
{
    /**
     * 返回当前 DAO 关联的模型类。
     *
     * @return class-string<Role> 模型类名
     */
    protected function model(): string
    {
        return Role::class;
    }

    /**
     * 按 code 查找角色。
     *
     * @param  string  $code  角色 code，全局唯一
     * @return Role|null 不存在返回 null
     */
    public function findByCode(string $code): ?Role
    {
        return $this
            ->query()
            ->where('code', $code)
            ->first();
    }

    /**
     * 分页获取角色列表，支持关键字与状态过滤。
     *
     * @param  int  $perPage  每页条数
     * @param  int  $page  1-based 页码
     * @param  string|null  $keyword  模糊匹配 code/name/name_zh
     * @param  int|null  $status  1=启用，0=禁用
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<Role> 角色分页器，包含当前页记录、总条数和分页信息
     */
    public function paginateList(
        int $perPage = 20,
        int $page = 1,
        ?string $keyword = null,
        ?int $status = null,
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        $query = $this
            ->query()
            ->orderBy('sort')
            ->orderBy('id');
        if ($keyword !== null && $keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($query) use ($like) {
                $query
                    ->where('code', 'ilike', $like)
                    ->orWhere('name', 'ilike', $like)
                    ->orWhere('name_zh', 'ilike', $like);
            });
        }
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 获取所有启用状态的角色。
     *
     * @return Collection<int, Role> 角色查询或计算结果集合；无匹配时为空集合
     */
    public function listActive(): Collection
    {
        return $this
            ->query()
            ->where('status', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * 按 ID 加载角色及其所有权限节点。
     *
     * @param  int  $id  角色主键 ID
     * @return Role|null 角色模型实例；未找到时返回 null
     */
    public function findWithRelations(int $id): ?Role
    {
        return $this
            ->query()
            ->with(['permissions'])
            ->find($id);
    }

    /**
     * 统计通过 users.role_id 或 user_roles 持有指定角色且处于启用状态的用户数。
     * 用于删除角色前的安全校验。
     *
     * @param  int  $roleId  角色主键 ID
     * @return int 活跃用户数
     */
    public function countActiveUsers(int $roleId): int
    {
        return \App\Models\User::query()
            ->where(fn ($query) => $query
                ->where('role_id', $roleId)
                ->orWhereHas('roles', fn ($role) => $role->where('roles.id', $roleId)))
            ->where('active', true)
            ->count();
    }
}
