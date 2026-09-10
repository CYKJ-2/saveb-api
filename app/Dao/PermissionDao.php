<?php

namespace App\Dao;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

/**
 * 权限节点数据访问对象。
 *
 * 同时处理菜单节点（type=menu）与操作权限点（type=action）；
 * 原"menus"表已下线，菜单与权限点统一在 permissions 表中维护。
 *
 * @extends BaseDao<Permission>
 */
class PermissionDao extends BaseDao
{
    /**
     * 返回当前 DAO 关联的模型类。
     *
     * @return class-string<Permission> 模型类名
     */
    protected function model(): string
    {
        return Permission::class;
    }

    /**
     * 按 code 查找权限节点。
     *
     * @param  string  $code  权限 code，全局唯一
     * @return Permission|null 不存在返回 null
     */
    public function findByCode(string $code): ?Permission
    {
        return $this
            ->query()
            ->where('code', $code)
            ->first();
    }

    /**
     * 广度优先收集节点及后代 ID，已访问节点不重复入队遍历。
     *
     * @param  int  $id  权限节点记录主键 ID
     * @return array 当前权限节点及全部后代的 ID 列表，按已访问节点去重
     */
    public function subtreeIds(int $id): array
    {
        $all = $this
            ->listAll()
            ->groupBy('parent_id');
        $ids = [];
        $queue = [$id];
        while ($queue) {
            $current = array_shift($queue);
            if (isset($ids[$current])) {
                continue;
            }
            $ids[$current] = $current;
            foreach ($all->get($current, collect()) as $child) {
                $queue[] = $child->id;
            }
        }

        return array_values($ids);
    }

    /**
     * 软删除权限节点及全部后代。
     *
     * @param  int  $id  权限节点记录主键 ID
     * @return void 无返回值；副作用见方法说明
     */
    public function deleteSubtree(int $id): void
    {
        $this
            ->query()
            ->whereIn('id', $this->subtreeIds($id))
            ->delete();
    }

    /**
     * 停用节点及其后代，返回受影响行数。
     *
     * @param  int  $id  权限节点记录主键 ID
     * @return int 受影响的记录条数
     */
    public function disableSubtree(int $id): int
    {
        return $this
            ->query()
            ->whereIn('id', $this->subtreeIds($id))
            ->update(['status' => 0]);
    }

    /* ─── Tree queries ──────────────────────────────────── */
    /**
     * 获取所有节点的扁平列表（按 level/sort/id 排序）。
     * 典型用途：前端表单下拉 / 服务端自行构树。
     *
     * @return Collection<int, Permission> 权限节点查询或计算结果集合；无匹配时为空集合
     */
    public function listAll(): Collection
    {
        return $this
            ->query()
            ->orderBy('level')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * 取整棵权限树（一次性 SQL 拉平全部节点，按 parent_id 自引用拼成嵌套树）。
     *
     * 与 listAll() 的差别：
     *   - 本方法会用 Eloquent 关系的 setRelation('children', ...) 把子树挂回
     *     到每个节点的 children 属性上，避免序列化时缺字段；
     *   - 返回结构是森林：根节点（parent_id=0）的集合，children 数组里嵌套子节点。
     *   - 全程只用一条 SQL（无 with() 递归预加载），在大数据量下比 Eloquent
     *     的 with() 递归策略更可控。
     *
     * 用法示例：
     *   $tree = $dao->listAllAsTree();
     *   // → [{ id:1, children:[{ id:2, children:[{ id:3 }] }] }, ...]
     *
     * @return Collection<int, Permission> 根节点集合，每个节点带 children 关系
     */
    public function listAllAsTree(): Collection
    {
        $flat = $this
            ->query()
            ->orderBy('level')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
        if ($flat->isEmpty()) {
            return $flat;
        }
        // 按 parent_id 分桶
        $byParent = [];
        foreach ($flat as $node) {
            $pid = (int) $node->parent_id;
            $byParent[$pid] ??= new Collection();
            $byParent[$pid]->push($node);
        }
        // 设置空 children 集合，避免序列化时缺字段
        foreach ($flat as $node) {
            $node->setRelation('children', $byParent[$node->id] ?? new Collection());
        }

        // 根节点集合（parent_id=0 或 parent_id 不存在的情况都算根）
        return $byParent[0] ?? new Collection();
    }

    /**
     * 获取所有 action 权限点，可按父菜单 ID 过滤。
     *
     * @param  int|null  $parentId  父菜单 ID；为 null 返回全部 action
     * @return Collection<int, Permission> 权限节点查询或计算结果集合；无匹配时为空集合
     */
    public function listActions(?int $parentId = null): Collection
    {
        $query = $this
            ->query()
            ->where('type', Permission::TYPE_ACTION)
            ->orderBy('sort');
        if ($parentId !== null) {
            $query->where('parent_id', $parentId);
        }

        return $query->get();
    }

    /* ─── RBAC helpers ─────────────────────────────────── */
    /**
     * 获取某角色已分配的全部权限节点 ID 列表。
     *
     * @param  int  $roleId  角色主键 ID
     * @return array<int> 权限节点 ID 列表（菜单 + action 都包含）
     */
    public function permissionIdsForRole(int $roleId): array
    {
        return \DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->all();
    }

    /**
     * 获取某角色已分配的所有权限节点（菜单 + action），并预加载 children。
     *
     * @param  int  $roleId  角色主键 ID
     * @return Collection<int, Permission> 权限节点查询或计算结果集合；无匹配时为空集合
     */
    public function allForRole(int $roleId): Collection
    {
        return $this
            ->query()
            ->whereHas('roles', fn ($query) => $query->where('roles.id', $roleId))
            ->with(['children'])
            ->orderBy('level')
            ->orderBy('sort')
            ->get();
    }

    /**
     * 获取某角色已分配的所有 action 权限节点。
     *
     * @param  int  $roleId  角色主键 ID
     * @return Collection<int, Permission> 权限节点查询或计算结果集合；无匹配时为空集合
     */
    public function actionsForRole(int $roleId): Collection
    {
        return $this
            ->query()
            ->whereHas('roles', fn ($query) => $query->where('roles.id', $roleId))
            ->where('type', Permission::TYPE_ACTION)
            ->orderBy('sort')
            ->get();
    }
}
