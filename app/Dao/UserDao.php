<?php

namespace App\Dao;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * 用户数据访问对象。
 *
 * @extends BaseDao<User>
 */
class UserDao extends BaseDao
{
    /**
     * 按用户名、显示名称和启用状态分页查询用户，预加载角色。
     *
     * @param  int  $page  页码，从 1 开始
     * @param  int  $perPage  每页条数
     * @param  bool|null  $active  启用状态筛选；null 表示不限制
     * @param  string|null  $username  登录用户名
     * @param  string|null  $displayName  用户显示名称筛选
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<User> 用户分页器，附带主角色与多角色
     */
    public function paginateFiltered(
        int $page,
        int $perPage,
        ?bool $active,
        ?string $username,
        ?string $displayName,
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        $query = $this
            ->query()
            ->with(['role', 'roles'])
            ->orderByDesc('id');
        if ($active !== null) {
            $query->where('active', $active);
        }
        if ($username !== null && $username !== '') {
            $query->where('username', 'ilike', '%' . $username . '%');
        }
        if ($displayName !== null && $displayName !== '') {
            $query->where('display_name', 'ilike', '%' . $displayName . '%');
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 指定当前 DAO 使用的 Eloquent 模型类。
     *
     * @return class-string<User> 模型类名
     */
    protected function model(): string
    {
        return User::class;
    }

    /**
     * 按用户名（大小写不敏感）查找单个用户。
     *
     * @param  string  $username  登录用户名
     * @return User|null 不存在时返回 null
     */
    public function findByUsername(string $username): ?User
    {
        return $this
            ->query()
            ->whereRaw('lower(username) = ?', [strtolower($username)])
            ->first();
    }

    /**
     * 按角色 code 查找启用的用户。
     *
     * 实现：通过 roles.code → users.role_id 关联查询。
     * 注意：原 users.role 字符串字段已下线，无法再用 whereIn('role', ...)。
     *
     * @param  array|string  $roles  角色 code 数组或字符串
     * @param  array  $fields  选择列，默认全选
     * @return Collection<int, User> 命中集合
     */
    public function findByRoles(array|string $roles, array $fields = ['*']): Collection
    {
        $roles = (array) $roles;
        if (empty($roles)) {
            return new Collection();
        }

        return $this
            ->query()
            ->whereHas('role', function ($query) use ($roles) {
                $query->whereIn('code', $roles);
            })
            ->where('active', 1)
            ->select($fields)
            ->orderBy('id')
            ->get();
    }

    /**
     * 分页获取启用用户，可按主角色 code 过滤。
     *
     * @param  array  $fields  选择列
     * @param  int  $perPage  每页条数
     * @param  int  $page  1-based 页码
     * @param  string|null  $role  角色 code 过滤（主角色 users.role_id）
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<User> 用户分页器，包含当前页记录、总条数和分页信息
     */
    public function listActive(
        array $fields = ['*'],
        int $perPage = 20,
        int $page = 1,
        ?string $role = null,
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        $query = $this
            ->query()
            ->where('active', 1)
            ->select($fields);
        if ($role !== null) {
            $query->whereHas('role', function ($query) use ($role) {
                $query->where('code', $role);
            });
        }

        return $query
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 停用指定用户（active=0）。
     *
     * @param  int  $userId  用户主键 ID
     * @return int 受影响行数（0 或 1）
     */
    public function deactivate(int $userId): int
    {
        return $this->updateWhere(['id' => $userId], ['active' => 0]);
    }

    /**
     * 批量停用多个用户。
     *
     * @param  array<int>  $userIds  用户 ID 列表
     * @return int 受影响行数
     */
    public function deactivateMany(array $userIds): int
    {
        return $this
            ->query()
            ->whereIn('id', $userIds)
            ->update(['active' => 0]);
    }

    /**
     * 检查用户名是否已被占用（用于创建/改名时唯一性校验）。
     *
     * @param  string  $username  待校验的用户名
     * @param  int|null  $excludeId  排除的用户 ID（改名时传当前用户自身）
     * @return bool 已占用返回 true
     */
    public function usernameTaken(string $username, ?int $excludeId = null): bool
    {
        $query = $this
            ->query()
            ->whereRaw('lower(username) = ?', [strtolower($username)]);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }
}
