<?php

namespace App\Services;

use App\Common\RespDef;
use App\Dao\PermissionDao;
use App\Exceptions\SystemException;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

/**
 * 权限节点业务服务。
 *
 * 架构：PermissionController → PermissionService → PermissionDao → Permission (Model)
 *
 * 业务规则：
 *   - 同一 code 全局唯一，创建时冲突抛 CODE_PERMISSION_ALREADY_EXISTS
 *   - 节点删除通过 DB 外键级联处理子节点与 role_permissions 关联
 *   - type=menu 与 type=action 在同一棵树上，level 由父节点自动推导
 *   - 菜单树 / 权限点 统一通过 permissions 表自引用树获取，不再有独立的 menus 表
 *   - 前端拿到统一的 permissions 树后自行按 type 拆分为导航与权限点
 */
class PermissionService
{
    /**
     * 构造函数，注入权限 DAO。
     *
     * @param  PermissionDao  $permissionDao  权限数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private readonly PermissionDao $permissionDao)
    {
    }

    /* ─── Read ──────────────────────────────────────────── */
    /**
     * 获取所有节点的扁平列表（按 level/sort/id 排序）。
     *
     * 典型用途：前端表单中的下拉选择 / 服务端构建树形结构的原始数据。
     *
     * @return Collection<int, Permission> 权限节点查询或计算结果集合；无匹配时为空集合
     * @see PermissionDao::listAll()
     */
    public function all(): Collection
    {
        return $this->permissionDao->listAll();
    }

    /**
     * 获取整棵权限树（森林结构）。
     *
     * 返回根节点集合（parent_id=0），每个节点带 children 关系递归至叶子。
     * 排序顺序：level asc → sort asc → id asc。
     *
     * 端点：GET /api/permissions/tree（前端用于角色分配菜单等场景）。
     *
     * @return Collection<int, Permission> 根节点集合（森林）
     * @see PermissionDao::listAllAsTree()
     */
    public function tree(): Collection
    {
        return $this->permissionDao->listAllAsTree();
    }

    /**
     * 获取所有 action 节点，可按父菜单过滤。
     *
     * @param  int|null  $parentId  父菜单 ID；为 null 返回全部 action
     * @return Collection<int, Permission> 权限节点查询或计算结果集合；无匹配时为空集合
     * @see PermissionDao::listActions()
     */
    public function actions(?int $parentId = null): Collection
    {
        return $this->permissionDao->listActions($parentId);
    }

    /**
     * 按 ID 查找单个节点。节点不存在时抛 404。
     *
     * @param  int  $id  权限节点主键 ID
     * @return Permission 权限节点模型实例
     * @throws SystemException  节点不存在时
     * @see PermissionDao::find()
     */
    public function find(int $id): Permission
    {
        $permission = $this->permissionDao->find($id);
        if (!$permission) {
            throw new SystemException(RespDef::CODE_PERMISSION_NOT_FOUND, RespDef::MSG_PERMISSION_NOT_FOUND, 404);
        }

        return $permission;
    }

    /**
     * 按 code 查找单个节点。节点不存在时抛 404。
     *
     * @param  string  $code  权限节点 code，全局唯一
     * @return Permission 权限节点模型实例
     * @throws SystemException  节点不存在时
     * @see PermissionDao::findByCode()
     */
    public function findByCode(string $code): Permission
    {
        $permission = $this->permissionDao->findByCode($code);
        if (!$permission) {
            throw new SystemException(RespDef::CODE_PERMISSION_NOT_FOUND, RespDef::MSG_PERMISSION_NOT_FOUND, 404);
        }

        return $permission;
    }

    /* ─── Write ──────────────────────────────────────────── */
    /**
     * 创建新权限节点。
     *
     * type=menu 时 path/icon/component 才有意义；type=action 时 action 字段才有意义。
     * level 若未指定则按 parent.level + 1 自动推导，最深 3 级。
     * code 已存在时抛 422 + CODE_PERMISSION_ALREADY_EXISTS。
     *
     * @param  array{ code: string, name: string, type?: string, parent_id?: int, name_zh?: string|null, path?: string|null, icon?: string|null, component?: string|null, action?: string, resource?: string|null, level?: int, is_menu_visible?: bool, hidden?: bool, sort?: int, status?: int, description?: string|null, description_zh?: string|null, }  $data  创建节点所需字段
     * @return Permission 新创建的节点（含主键 ID）
     * @throws SystemException  code 重复或父节点不存在
     * @see PermissionDao::findByCode()
     * @see PermissionDao::create()
     */
    public function create(array $data): Permission
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $data['code'])) {
            throw new SystemException(RespDef::CODE_INVALID_PARAMS, '权限码仅允许小写字母、数字、点、下划线和连字符。', 422);
        }
        if ($this->permissionDao->findByCode($data['code'])) {
            throw new SystemException(RespDef::CODE_PERMISSION_ALREADY_EXISTS, RespDef::MSG_PERMISSION_ALREADY_EXISTS, 422);
        }
        $type = $data['type'] ?? Permission::TYPE_MENU;
        $parentId = (int) ($data['parent_id'] ?? 0);
        $level = $this->resolveLevel($parentId, (int) ($data['level'] ?? 1), $type);

        return $this->permissionDao->create([
            'parent_id' => $parentId,
            'code' => $data['code'],
            'name' => $data['name'],
            'name_zh' => $data['name_zh'] ?? null,
            'type' => $type,
            'path' => $type === Permission::TYPE_MENU ? $data['path'] ?? null : null,
            'icon' => $type === Permission::TYPE_MENU ? $data['icon'] ?? null : null,
            'component' => $type === Permission::TYPE_MENU ? $data['component'] ?? null : null,
            'action' => $type === Permission::TYPE_ACTION ? $data['action'] ?? Permission::ACTION_CUSTOM : Permission::ACTION_CUSTOM,
            'resource' => $data['resource'] ?? null,
            'level' => $level,
            'is_menu_visible' => $type === Permission::TYPE_MENU ? (bool) ($data['is_menu_visible'] ?? true) : false,
            'hidden' => (bool) ($data['hidden'] ?? false),
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? 1),
            'description' => $data['description'] ?? null,
            'description_zh' => $data['description_zh'] ?? null,
        ]);
    }

    /**
     * 更新已有权限节点。
     *
     * 仅修改传入的字段（部分更新）。code 字段不允许修改。
     * parent_id 变更时会重新推导 level。
     *
     * status=0 时级联禁用所有子孙节点（递归整棵子树）；status=1 时不级联
     * 启用（启用是显式操作，避免误操作把已单独禁用的子节点一并打开）。
     *
     * @param  int  $id  节点主键 ID
     * @param  array  $data  待更新字段（与 create 同 schema）
     * @return Permission 更新后的节点
     * @throws SystemException  节点不存在
     * @see PermissionDao::find()
     * @see PermissionDao::subtreeIds()
     * @see PermissionDao::updateWhere()
     */
    public function update(int $id, array $data): Permission
    {
        $permission = $this->permissionDao->find($id);
        if (!$permission) {
            throw new SystemException(RespDef::CODE_PERMISSION_NOT_FOUND, RespDef::MSG_PERMISSION_NOT_FOUND, 404);
        }
        $update = [];
        $nullableStrFields = [
            'name',
            'name_zh',
            'path',
            'icon',
            'component',
            'resource',
            'description',
            'description_zh',
        ];
        foreach ($nullableStrFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (array_key_exists('parent_id', $data)) {
            $newParent = (int) $data['parent_id'];
            if (in_array($newParent, $this->permissionDao->subtreeIds($id), true)) {
                throw new SystemException(RespDef::CODE_INVALID_PARAMS, '父节点不能是自身或子孙节点。', 422);
            }
            $update['parent_id'] = $newParent;
            $update['level'] = $this->resolveLevel($newParent, (int) ($data['level'] ?? $permission->level), $permission->type);
        }
        $intFields = ['sort', 'action'];
        foreach ($intFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        $boolFields = ['hidden', 'is_menu_visible'];
        foreach ($boolFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = (bool) $data[$field];
            }
        }
        // status 字段需要单独的级联逻辑：禁用父节点要把整棵子树一起禁掉；
        // 启用父节点不级联（启用是显式操作，避免误开启已被单独禁用的子节点）。
        $cascadeDisable = false;
        if (array_key_exists('status', $data)) {
            $newStatus = (int) $data['status'];
            $update['status'] = $newStatus;
            $cascadeDisable = $newStatus === 0;
        }
        if (!empty($update)) {
            $this->permissionDao->updateWhere(['id' => $id], $update);
        }
        if ($cascadeDisable) {
            $this->cascadeDisableDescendants($id);
        }

        return $this->find($id);
    }

    /**
     * 把节点及其整棵子孙树全部置为禁用（status=0）。
     *
     * 用 BFS（队列）方式遍历，避免深层级递归爆栈；用 visited 集合防御性
     * 防止意外回环（虽然树结构上不该有回环）。
     *
     * 不级联启用：启用是显式动作，操作者应当单独勾选要打开的子节点。
     *
     * @param  int  $rootId  待停用的根节点 ID，更新范围包含自身和全部后代
     * @return int 根节点及后代中受更新影响的记录条数
     * @see PermissionDao::disableSubtree()
     */
    public function cascadeDisableDescendants(int $rootId): int
    {
        return $this->permissionDao->disableSubtree($rootId);
    }

    /**
     * 软删除权限节点及全部后代；保留授权关联，由有效权限计算排除已删除节点。
     *
     * @param  int  $id  节点主键 ID
     * @return void 无返回值；副作用见方法说明
     * @throws SystemException  节点不存在
     * @see PermissionDao::find()
     * @see PermissionDao::deleteSubtree()
     */
    public function delete(int $id): void
    {
        $permission = $this->permissionDao->find($id);
        if (!$permission) {
            throw new SystemException(RespDef::CODE_PERMISSION_NOT_FOUND, RespDef::MSG_PERMISSION_NOT_FOUND, 404);
        }
        // 通过 DAO 显式软删除整棵子树，不能依赖物理删除的外键级联。
        $this->permissionDao->deleteSubtree($id);
    }

    /* ─── Private helpers ──────────────────────────────── */
    /**
     * 推导节点的 level 值：
     *   - 顶级节点（parent_id=0）→ level=1
     *   - menu 子节点 → parent.level + 1，上限 3
     *   - action 节点 → 继承父级 level
     *
     * @param  int  $parentId  父节点 ID（0=顶级）
     * @param  int  $requested  调用方显式指定的 level（不强制使用）
     * @param  string  $type  节点类型：menu | action
     * @return int 推导后的 level
     * @see PermissionDao::find()
     */
    private function resolveLevel(
        int $parentId,
        int $requested,
        string $type,
    ): int {
        if ($parentId === 0) {
            return 1;
        }
        $parent = $this->permissionDao->find($parentId);
        if (!$parent || $parent->type !== Permission::TYPE_MENU) {
            throw new SystemException(RespDef::CODE_INVALID_PARAMS, '父节点必须是存在的菜单。', 422);
        }
        if ($type === Permission::TYPE_ACTION) {
            return $parent->level;
            // action 与父菜单同 level
        }

        return min(3, $parent->level + 1);
    }
}
