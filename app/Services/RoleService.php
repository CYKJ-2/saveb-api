<?php

namespace App\Services;

use App\Common\RespDef;
use App\Dao\PermissionDao;
use App\Dao\RoleDao;
use App\Exceptions\SystemException;
use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 角色业务服务。
 *
 * 架构：RoleController → RoleService → [RoleDao | PermissionDao] → Model
 *
 * 业务规则：
 *   - 内置角色（is_system=true）不可删除，code 不可改名
 *   - 仍有活跃用户挂载的角色不可删除
 *   - 权限分配支持以 ID 或 code 两种入参形式
 */
class RoleService
{
    /**
     * 构造函数，注入角色与权限两个 DAO。
     *
     * @param  RoleDao  $roleDao  角色数据访问对象
     * @param  PermissionDao  $permissionDao  权限数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private readonly RoleDao $roleDao, private readonly PermissionDao $permissionDao)
    {
    }

    /**
     * 分页获取角色列表，支持关键字与状态过滤。
     *
     * @param  int  $page  1-based 页码
     * @param  int  $perPage  每页条数
     * @param  string|null  $keyword  模糊匹配 code/name/name_zh；为 null 不过滤
     * @param  int|null  $status  状态过滤：1=启用，0=禁用；为 null 不过滤
     * @return LengthAwarePaginator<Role> 角色分页器，包含当前页记录、总条数和分页信息
     * @see RoleDao::paginateList()
     */
    public function list(
        int $page,
        int $perPage,
        ?string $keyword,
        ?int $status,
    ): LengthAwarePaginator {
        return $this->roleDao->paginateList($perPage, $page, $keyword, $status);
    }

    /**
     * 获取所有启用状态的角色（扁平列表），不分页。
     * 典型用途：下拉选择器数据源。
     *
     * @return array<int, Role> 全部启用角色模型的扁平数组，供选项框使用
     */
    public function all(): array
    {
        return $this->roleDao
            ->listActive()
            ->all();
    }

    /**
     * 按 ID 获取角色详情（附带权限节点）。不存在时抛 404。
     *
     * @param  int  $id  角色主键 ID
     * @return Role 角色模型实例
     * @throws SystemException
     * @see RoleDao::findWithRelations()
     */
    public function find(int $id): Role
    {
        $role = $this->roleDao->findWithRelations($id);
        if (!$role) {
            throw new SystemException(RespDef::CODE_ROLE_NOT_FOUND, RespDef::MSG_ROLE_NOT_FOUND, 404);
        }

        return $role;
    }

    /**
     * 创建新角色。code 已存在时抛 422 + CODE_ROLE_ALREADY_EXISTS。
     *
     * @param  array  $data  角色字段（见 RoleController::store 的 schema）
     * @return Role 新创建的角色
     * @throws SystemException
     * @see RoleDao::findByCode()
     * @see RoleDao::create()
     */
    public function create(array $data): Role
    {
        if ($this->roleDao->findByCode($data['code'])) {
            throw new SystemException(RespDef::CODE_ROLE_ALREADY_EXISTS, RespDef::MSG_ROLE_ALREADY_EXISTS, 422);
        }

        return $this->roleDao->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'name_zh' => $data['name_zh'] ?? null,
            'description' => $data['description'] ?? null,
            'description_zh' => $data['description_zh'] ?? null,
            'status' => (int) ($data['status'] ?? 1),
            'is_system' => false,
            'sort' => (int) ($data['sort'] ?? 100),
        ]);
    }

    /**
     * 更新已有角色。
     *
     * 规则：
     *   - 内置角色（is_system=true）拒绝修改；普通角色的 code 字段不参与更新
     *   - 未传入的字段保持原值；显式传 null 的可空字段会被置空
     *
     * @param  int  $id  角色主键 ID
     * @param  array  $data  待更新字段
     * @return Role 更新后的角色（含 permissions 关系）
     * @throws SystemException  角色不存在
     * @see RoleDao::find()
     * @see RoleDao::updateWhere()
     * @see RoleDao::findWithRelations()
     */
    public function update(int $id, array $data): Role
    {
        $role = $this->roleDao->find($id);
        if (!$role) {
            throw new SystemException(RespDef::CODE_ROLE_NOT_FOUND, RespDef::MSG_ROLE_NOT_FOUND, 404);
        }
        if ($role->is_system) {
            // 内置角色禁止任何修改（防止误改名称、状态、排序等）
            throw new SystemException(RespDef::CODE_SYSTEM_ROLE_PROTECTED, RespDef::MSG_SYSTEM_ROLE_PROTECTED, 422);
        }
        $update = [];
        $strFields = ['name', 'name_zh', 'description', 'description_zh'];
        foreach ($strFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (array_key_exists('status', $data)) {
            $update['status'] = (int) $data['status'];
        }
        if (array_key_exists('sort', $data)) {
            $update['sort'] = (int) $data['sort'];
        }
        if (!empty($update)) {
            $this->roleDao->updateWhere(['id' => $id], $update);
        }

        return $this->roleDao->findWithRelations($id);
    }

    /**
     * 删除角色。
     *
     * 限制：
     *   - 内置角色（is_system=true）不可删
     *   - 当前仍有活跃用户挂载时不可删
     *
     * @param  int  $id  角色主键 ID
     * @return void 无返回值；副作用见方法说明
     * @throws SystemException  角色不存在 / 内置角色保护 / 仍有用户挂载
     * @see RoleDao::find()
     * @see RoleDao::countActiveUsers()
     * @see RoleDao::deleteWhere()
     */
    public function delete(int $id): void
    {
        $role = $this->roleDao->find($id);
        if (!$role) {
            throw new SystemException(RespDef::CODE_ROLE_NOT_FOUND, RespDef::MSG_ROLE_NOT_FOUND, 404);
        }
        if ($role->is_system) {
            throw new SystemException(RespDef::CODE_SYSTEM_ROLE_PROTECTED, RespDef::MSG_SYSTEM_ROLE_PROTECTED, 422);
        }
        if ($this->roleDao->countActiveUsers($id) > 0) {
            throw new SystemException(RespDef::CODE_ROLE_HAS_USERS, RespDef::MSG_ROLE_HAS_USERS, 422);
        }
        $this->roleDao->deleteWhere(['id' => $id]);
    }

    /**
     * 全量替换角色的权限分配。
     * 入参可同时包含权限节点 ID 与 code（混传时优先按 ID 处理）。
     *
     * @param  int  $roleId  角色主键 ID
     * @param  array<int|string>  $permissionIdsOrCodes  权限节点 ID 或 code 列表
     * @return Role 更新后的角色（含 permissions 关系）
     * @throws SystemException  角色不存在
     * @see RoleDao::find()
     * @see RoleDao::findWithRelations()
     */
    public function assignPermissions(int $roleId, array $permissionIdsOrCodes): Role
    {
        $role = $this->roleDao->find($roleId);
        if (!$role) {
            throw new SystemException(RespDef::CODE_ROLE_NOT_FOUND, RespDef::MSG_ROLE_NOT_FOUND, 404);
        }
        $actor = request()->attributes->get('auth_user');
        if ($actor) {
            $codes = app(RbacService::class)->codes($actor);
            $requested = $this->permissionDao
                ->listAll()
                ->filter(fn ($permission) => in_array($permission->id, $permissionIdsOrCodes) || in_array($permission->code, $permissionIdsOrCodes, true));
            if (!in_array('*', $codes, true) && ($role->code === 'super_admin' || array_diff($requested
                ->pluck('code')
                ->all(), $codes))) {
                throw new SystemException(RespDef::CODE_PERMISSION_DENIED, '不能授予超出自身范围的权限。', 403);
            }
        }
        $role->syncPermissions($permissionIdsOrCodes);

        return $this->roleDao->findWithRelations($roleId);
    }

    /**
     * 获取某角色已分配的全部权限节点 ID 列表。
     *
     * @param  int  $roleId  角色主键 ID
     * @return array{ permissions: array<int> } 角色结果数组；返回字段：permissions
     * @throws SystemException  角色不存在
     * @see RoleDao::find()
     * @see PermissionDao::permissionIdsForRole()
     */
    public function getAssignments(int $roleId): array
    {
        $role = $this->roleDao->find($roleId);
        if (!$role) {
            throw new SystemException(RespDef::CODE_ROLE_NOT_FOUND, RespDef::MSG_ROLE_NOT_FOUND, 404);
        }

        return ['permissions' => $this->permissionDao->permissionIdsForRole($roleId)];
    }
}
