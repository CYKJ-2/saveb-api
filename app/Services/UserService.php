<?php

namespace App\Services;

use App\Common\RespDef;
use App\Dao\UserDao;
use App\Exceptions\SystemException;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * 用户业务服务。
 *
 * 架构：UserController → UserService → UserDao → User (Model)
 *
 * 职责范围：
 *   - 用户的增删改查（含密码哈希）
 *   - 用户与角色集合（user_roles pivot）的写入
 *   - 用户拥有的权限点聚合（跨角色去重）
 *
 * 设计要点：写库时一律用 Eloquent 关系操作，DB 字段变更请同步更新 $fillable。
 */
class UserService
{
    /**
     * 构造函数，注入用户 DAO。
     *
     * @param  UserDao  $userDao  用户数据访问对象
     */
    public function __construct(private readonly UserDao $userDao)
    {
    }

    /**
     * 分页获取用户列表。默认按 id 倒序，附带主角色与多角色。
     *
     * 注意：select 中不能包含 `role`，该列已下线，role 信息通过关联 role / roles 获取。
     *
     * @param  int   $page        1-based 页码，默认 1
     * @param  int   $perPage     每页条数，默认 15
     * @param  bool  $activeOnly  是否仅显示启用账户，默认 false
     * @return LengthAwarePaginator<User>
     */
    public function list(
        int $page = 1,
        int $perPage = 20,
        ?bool $active = null,
        ?string $username = null,
        ?string $displayName = null,
    ): LengthAwarePaginator {
        return $this->userDao->paginateFiltered($page, $perPage, $active, $username, $displayName);
    }

    /**
     * 不分页，获取所有用户。用于小型数据集或导出场景。
     *
     * @param  bool  $activeOnly  是否仅显示启用账户，默认 false
     * @return Collection<int, User>
     */
    public function all(bool $activeOnly = false): Collection
    {
        $query = User::query()
            ->select([
                'id',
                'username',
                'display_name',
                'role_id',
                'staff_code',
                'active',
                'must_change_password',
                'created_at',
                'updated_at',
            ])
            ->with(['role:id,code,name,name_zh', 'roles:id,code,name,name_zh'])
            ->orderBy('id', 'desc');
        if ($activeOnly) {
            $query->where('active', 1);
        }

        return $query->get();
    }

    /**
     * 按 ID 获取单个用户，含主角色与多角色。
     *
     * @param  int  $id  用户主键 ID
     * @return User|null 不存在时返回 null
     */
    public function find(int $id): ?User
    {
        return User::with(['role:id,code,name,name_zh', 'roles:id,code,name,name_zh'])->find($id);
    }

    /**
     * 按用户名（大小写不敏感）查找用户。
     *
     * @param  string  $username  登录用户名
     * @return User|null          不存在时返回 null
     */
    public function findByUsername(string $username): ?User
    {
        return User::with(['role:id,code,name,name_zh', 'roles:id,code,name,name_zh'])
            ->whereRaw('lower(username) = ?', [strtolower($username)])
            ->first();
    }

    /**
     * 统计用户总数。
     *
     * @param  bool  $activeOnly  是否仅统计启用账户
     * @return int               用户数量
     */
    public function count(bool $activeOnly = false): int
    {
        $query = User::query();
        if ($activeOnly) {
            $query->where('active', 1);
        }

        return $query->count();
    }

    /**
     * 创建新用户。username 必须全局唯一。
     *
     * @param  array  $data  字段（参见 UserController::store 的 schema）
     * @return User         新建用户（含主角色与多角色关系）
     * @throws SystemException  username 重复时抛 422 + CODE_USER_ALREADY_EXISTS
     */
    public function create(array $data): User
    {
        // 检查用户名唯一性
        if ($this->userDao->usernameTaken($data['username'])) {
            throw new SystemException(RespDef::CODE_USER_ALREADY_EXISTS, RespDef::MSG_USER_ALREADY_EXISTS, 422);
        }
        $user = $this->userDao->create([
            'username' => $data['username'],
            'password_hash' => Hash::make($data['password']),
            'display_name' => $data['display_name'] ?? $data['username'],
            'role_id' => $data['role_id'] ?? null,
            'staff_code' => $data['staff_code'] ?? null,
            'active' => (int) ($data['active'] ?? 1),
            'must_change_password' => (bool) ($data['must_change_password'] ?? false),
        ]);
        // 若提供了多角色 ID 列表则同步 user_roles pivot
        if (!empty($data['role_ids'])) {
            app(UserRoleService::class)->sync($user, $data['role_ids']);
        }

        return $user->load(['role:id,code,name,name_zh', 'roles:id,code,name,name_zh']);
    }

    /**
     * 更新已有用户。username/password/role_id/role_ids 等按需更新。
     *
     * @param  int    $id    用户主键 ID
     * @param  array  $data  待更新字段
     * @return User         更新后的用户
     * @throws SystemException  用户不存在 / username 冲突
     */
    public function update(int $id, array $data): User
    {
        if ($id === request()->attributes->get('auth_user')?->id && array_key_exists('active', $data) && !$data['active']) {
            throw new SystemException(RespDef::CODE_INVALID_PARAMS, '不能禁用当前登录账户。', 422);
        }
        $user = $this->userDao->find($id);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }
        $updateData = [];
        // 密码可选更新（自动 bcrypt）
        if (!empty($data['password'])) {
            $updateData['password_hash'] = Hash::make($data['password']);
        }
        // 用户名变更需校验唯一性（排除自身）
        if (!empty($data['username']) && $data['username'] !== $user->username) {
            if ($this->userDao->usernameTaken($data['username'], $id)) {
                throw new SystemException(RespDef::CODE_USER_ALREADY_EXISTS, RespDef::MSG_USER_ALREADY_EXISTS, 422);
            }
            $updateData['username'] = $data['username'];
        }
        if (isset($data['display_name'])) {
            $updateData['display_name'] = $data['display_name'];
        }
        if (array_key_exists('role_id', $data)) {
            $updateData['role_id'] = $data['role_id'];
        }
        if (array_key_exists('staff_code', $data)) {
            $updateData['staff_code'] = $data['staff_code'];
        }
        if (isset($data['active'])) {
            $updateData['active'] = (int) $data['active'];
        }
        if (isset($data['must_change_password'])) {
            $updateData['must_change_password'] = (bool) $data['must_change_password'];
        }
        if (!empty($data['password']) || array_key_exists('active', $data) && !$data['active']) {
            $user
                ->apiTokens()
                ->delete();
        }
        if (!empty($updateData)) {
            $this->userDao->updateWhere(['id' => $id], $updateData);
        }
        // 提供 role_ids 时全量替换 user_roles pivot
        if (array_key_exists('role_ids', $data)) {
            app(UserRoleService::class)->sync($user, $data['role_ids'] ?? []);
        }

        return $this->find($id);
    }

    /**
     * 软删除用户。不允许删除当前登录账户本身。
     *
     * @param  int  $id  用户主键 ID
     * @throws SystemException  用户不存在 / 试图删除自己时
     */
    public function delete(int $id): void
    {
        $user = $this->userDao->find($id);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }
        // 不允许删除自己
        $currentUserId = request()->attributes->get('auth_user')?->id;
        if ($currentUserId && $currentUserId === $id) {
            throw new SystemException(-1203, 'You cannot delete your own account.', 422);
        }
        $this->userDao->deleteWhere(['id' => $id]);
    }

    /**
     * 修改用户密码，同时把 must_change_password 置为 false。
     *
     * @param  int     $id          用户主键 ID
     * @param  string  $newPassword 新密码（明文，自动 bcrypt）
     * @throws SystemException  用户不存在
     */
    public function changePassword(int $id, string $newPassword): void
    {
        $user = $this->userDao->find($id);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }
        $this->userDao->updateWhere(['id' => $id], [
            'password_hash' => Hash::make($newPassword),
            'must_change_password' => false,
        ]);
        $user
            ->apiTokens()
            ->delete();
    }

    /**
     * 获取指定用户的所有角色（user_roles pivot）。
     *
     * @param  int  $userId  用户主键 ID
     * @return Collection<int, Role>
     * @throws SystemException  用户不存在
     */
    public function getUserRoles(int $userId): Collection
    {
        $user = $this->userDao->find($userId);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }

        return $user->roles;
    }

    /**
     * 获取指定用户在所有角色下拥有的权限点（已去重）。
     *
     * 数据源：用户的所有角色 → 角色的所有权限节点（type=action 与 type=menu 都可）。
     *
     * @param  int    $userId  用户主键 ID
     * @return array  权限节点数组，每项包含 id/code/name/name_zh/action/type
     * @throws SystemException  用户不存在
     */
    public function getUserPermissions(int $userId): array
    {
        $user = $this->userDao->find($userId);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }
        $permissions = app(RbacService::class)
            ->nodes($user)
            ->map(fn ($permission) => [
                'id' => $permission->id,
                'code' => $permission->code,
                'name' => $permission->name,
                'name_zh' => $permission->name_zh,
                'action' => $permission->action,
                'type' => $permission->type,
            ])
            ->all();

        return $permissions;
    }
}
