<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 统一权限节点模型——同时承载菜单节点和操作权限点。
 *
 * 设计要点：
 *   - 菜单与权限点合并到一张 permissions 表，通过 type 字段区分
 *   - type='menu'   在侧边栏可见，参与前端导航
 *   - type='action' 纯权限点，仅用于后端 API 校验
 *   - self-referencing parent_id 自引用，0 表示顶级
 *   - 三级层级：模块（1）→ 页面（2）→ 按钮（3）
 *   - 原 menus 表已下线，所有节点都通过本表统一管理
 *
 * @property int          $id
 * @property int          $parent_id        0=顶级；引用 permissions.id
 * @property string       $code             全局唯一，例如 "system.user.create"
 * @property string       $name             显示名称（英文/默认）
 * @property string|null  $name_zh          中文显示名称（前端中英切换）
 * @property string       $type             menu | action
 * @property string|null  $path             前端路由路径（menu 节点才有意义）
 * @property string|null  $icon             antd 图标名（menu 节点才有意义）
 * @property string|null  $component        Vue 组件路径（menu 节点才有意义）
 * @property string       $action           list|create|update|delete|export|custom（action 节点才有意义）
 * @property string|null  $resource         资源标识，用于细粒度权限控制
 * @property int          $level            层级：1=模块，2=页面，3=按钮
 * @property bool         $is_menu_visible  是否在侧边栏显示
 * @property bool         $hidden           隐藏但仍可访问
 * @property int          $sort             同级排序权重
 * @property int          $status           1=启用，0=禁用
 * @property string|null  $description      英文描述
 * @property string|null  $description_zh   中文描述
 * @property-read \App\Models\Permission|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Permission> $children
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 */
class Permission extends BaseModel
{
    /* ─── 类型与动作常量 ───────────────────────────────── */
    /** 节点类型：菜单 */
    public const TYPE_MENU = 'menu';

    /** 节点类型：操作权限点 */
    public const TYPE_ACTION = 'action';

    /** action 枚举：列表 */
    public const ACTION_LIST = 'list';

    /** action 枚举：新建 */
    public const ACTION_CREATE = 'create';

    /** action 枚举：更新 */
    public const ACTION_UPDATE = 'update';

    /** action 枚举：删除 */
    public const ACTION_DELETE = 'delete';

    /** action 枚举：导出 */
    public const ACTION_EXPORT = 'export';

    /** action 枚举：自定义操作 */
    public const ACTION_CUSTOM = 'custom';

    /** @var string 表名 */
    protected $table = 'permissions';

    /** @var array<int, string> 可批量赋值字段 */
    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'name_zh',
        'type',
        'path',
        'icon',
        'component',
        'action',
        'resource',
        'level',
        'is_menu_visible',
        'hidden',
        'sort',
        'status',
        'description',
        'description_zh',
    ];

    /**
     * 字段类型转换。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'level' => 'integer',
            'sort' => 'integer',
            'status' => 'integer',
            'is_menu_visible' => 'boolean',
            'hidden' => 'boolean',
        ];
    }

    /* ─── Tree relations ───────────────────────────────── */
    /**
     * 父节点关联（self-referencing）。
     *
     * @return BelongsTo<Permission, Permission>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'parent_id');
    }

    /**
     * 直接子节点（一级）。
     *
     * @return HasMany<Permission, Permission>
     */
    public function children(): HasMany
    {
        return $this
            ->hasMany(Permission::class, 'parent_id')
            ->orderBy('sort')
            ->orderBy('id');
    }

    /**
     * 直接子节点的别名，语义清晰。
     *
     * @return HasMany<Permission, Permission>
     */
    public function directChildren(): HasMany
    {
        return $this->children();
    }

    /* ─── RBAC relations ───────────────────────────────── */
    /**
     * 拥有该权限节点的所有角色。
     *
     * @return BelongsToMany<Role, Permission>
     */
    public function roles(): BelongsToMany
    {
        return $this
            ->belongsToMany(Role::class, 'role_permissions', 'permission_id', 'role_id')
            ->withTimestamps();
    }

    /* ─── i18n helpers ────────────────────────────────── */
    /**
     * 按 locale 解析显示名称。
     * 中文 locale 且有 name_zh 时返回中文，否则回退英文。
     *
     * @param  string  $locale  例如 'en' | 'zh-CN' | 'zh'
     * @return string           非空的显示名称
     */
    public function nameIn(string $locale): string
    {
        if ($this->isChinese($locale) && !empty($this->name_zh)) {
            return $this->name_zh;
        }

        return $this->name;
    }

    /**
     * 按 locale 解析描述。
     *
     * @param  string    $locale
     * @return string|null
     */
    public function descriptionIn(string $locale): ?string
    {
        if ($this->isChinese($locale) && !empty($this->description_zh)) {
            return $this->description_zh;
        }

        return $this->description;
    }

    /**
     * 结构化 i18n 字段，前端可直接 p.i18n.name[locale]。
     *
     * @return array{
     *   name: array{en: string, zh: ?string},
     *   description: array{en: ?string, zh: ?string}
     * }
     */
    public function i18n(): array
    {
        return [
            'name' => [
                'en' => $this->name,
                'zh' => $this->name_zh,
            ],
            'description' => [
                'en' => $this->description,
                'zh' => $this->description_zh,
            ],
        ];
    }

    /**
     * 判断给定 locale 是否属于中文族。
     *
     * @param  string  $locale
     * @return bool
     */
    private function isChinese(string $locale): bool
    {
        return in_array(strtolower($locale), ['zh', 'zh-cn', 'zh-tw', 'zh-hk'], true);
    }
}
