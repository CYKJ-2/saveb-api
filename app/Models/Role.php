<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 角色实体模型，对应 roles 表。
 *
 * @property int         $id
 * @property string      $code              角色 code，全局唯一，例如 "super_admin"
 * @property string      $name              显示名称（英文/默认）
 * @property string|null $name_zh           中文显示名称（前端中英切换）
 * @property string|null $description       英文描述
 * @property string|null $description_zh    中文描述
 * @property int         $status            1=启用，0=禁用
 * @property bool        $is_system         内置角色标记，true 不可删除/改名
 * @property int         $sort              排序权重
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Permission> $permissions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 */
class Role extends BaseModel
{
    /** @var string 表名 */
    protected $table = 'roles';

    /** @var array<int, string> 可批量赋值字段 */
    protected $fillable = [
        'code',
        'name',
        'name_zh',
        'description',
        'description_zh',
        'status',
        'is_system',
        'sort',
    ];

    /**
     * 字段类型转换。
     *
     * @return array<string, string> 数据库字段名到 Eloquent 转换类型的映射
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'is_system' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /* ─── Relations ─────────────────────────────────────── */
    /**
     * 角色拥有的权限节点集合（含菜单与 action）。
     *
     * @return BelongsToMany<Permission, Role> 用于加载或继续约束该关联的 Eloquent 关系对象
     */
    public function permissions(): BelongsToMany
    {
        return $this
            ->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id')
            ->using(RolePermission::class)
            ->withTimestamps();
    }

    /**
     * 兼容遗留的"单一角色"反查：通过 users.role_id 指向本角色的用户。
     *
     * @return HasMany<User, Role> 用于加载或继续约束该关联的 Eloquent 关系对象
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    /* ─── Helpers ──────────────────────────────────────── */
    /**
     * 全量替换角色的权限分配（写入 role_permissions pivot）。
     * 入参可为权限 ID 或 code，方法内部自动识别。
     *
     * @param  array<int|string>  $permissionIdsOrCodes  权限节点 ID 或 code
     * @return void 无返回值；副作用见方法说明
     */
    public function syncPermissions(array $permissionIdsOrCodes): void
    {
        $ids = $this->resolvePermissionIds($permissionIdsOrCodes);
        $this
            ->permissions()
            ->sync($ids);
    }

    /* ─── i18n helpers ────────────────────────────────── */
    /**
     * 按 locale 解析显示名称。
     *
     * @param  string  $locale  例如 'en' | 'zh-CN' | 'zh'
     * @return string 中文 locale 时返回 name_zh，否则返回 name
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
     * @param  string  $locale  当前界面语言，如 zh-CN 或 en-US
     * @return string|null 角色模型实例；未找到时返回 null
     */
    public function descriptionIn(string $locale): ?string
    {
        if ($this->isChinese($locale) && !empty($this->description_zh)) {
            return $this->description_zh;
        }

        return $this->description;
    }

    /**
     * 构造结构化 i18n 字段，便于前端按 locale 直接索引。
     *
     * @return array{ name: array{en: string, zh: ?string}, description: array{en: ?string, zh: ?string} } 角色结果数组；返回字段：name、description
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
     * @param  string  $locale  当前界面语言，如 zh-CN 或 en-US
     * @return bool locale 以 zh 开头时为 true
     */
    private function isChinese(string $locale): bool
    {
        return in_array(strtolower($locale), ['zh', 'zh-cn', 'zh-tw', 'zh-hk'], true);
    }

    /**
     * 把"权限 ID 或 code 数组"统一转换为 ID 数组。
     *
     * @param  array<int|string>  $values  待写入的字段值
     * @return array<int> 统一为 int 列表
     */
    private function resolvePermissionIds(array $values): array
    {
        if (empty($values)) {
            return [];
        }
        $isNumeric = array_reduce($values, fn ($carry, $v) => $carry && (is_int($v) || is_string($v) && ctype_digit($v)), true);
        if ($isNumeric) {
            return array_map('intval', $values);
        }

        return Permission::whereIn('code', $values)
            ->pluck('id')
            ->all();
    }
}
