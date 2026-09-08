<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 用户实体模型，对应 users 表。
 *
 * 角色信息通过关联获取：
 *   - 主角色：users.role_id → roles.id（HasOne 关系方法 role()）
 *   - 多角色：user_roles pivot（多对多关系方法 roles()）
 *
 * 注意：早期设计中的字符串字段 `role` 已下线，role 信息只能通过关联访问。
 *
 * @property int         $id
 * @property string      $username              登录用户名（全局唯一）
 * @property string      $password_hash         bcrypt/argon2 哈希值，序列化时自动隐藏
 * @property string      $display_name          界面显示名称
 * @property int|null    $role_id               主角色 ID（指向 roles.id）
 * @property string|null $staff_code            员工编码
 * @property int         $active                1=启用，0=禁用
 * @property bool        $must_change_password  下次登录是否强制改密
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 * @property Carbon|null $deleted_at
 * @property-read \App\Models\Role|null $role
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ApiToken> $apiTokens
 */
class User extends BaseModel
{
    /** @var string 表名 */
    protected $table = 'users';

    /** @var array<int, string> 可批量赋值的字段 */
    protected $fillable = [
        'username',
        'password_hash',
        'display_name',
        'role_id',
        'staff_code',
        'active',
        'must_change_password',
    ];

    /** @var array<int, string> 序列化时自动隐藏的字段 */
    protected $hidden = ['password_hash'];

    /**
     * 字段类型转换。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'integer',
            'must_change_password' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /* ─── Relations ──────────────────────────────────── */
    /**
     * 主角色关联：通过 users.role_id → roles.id（向后兼容）。
     *
     * @return BelongsTo<Role, User>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * 多角色关联：通过 user_roles pivot 表，支持一个用户挂多个角色。
     *
     * @return BelongsToMany<Role, User>
     */
    public function roles(): BelongsToMany
    {
        return $this
            ->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->using(UserRole::class)
            ->withPivot('granted_by_user_id')
            ->withTimestamps();
    }

    /**
     * 用户签发的 API token 集合。
     *
     * @return HasMany<ApiToken, User>
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class, 'user_id');
    }

    /* ─── Helpers ─────────────────────────────────────── */
    /**
     * 一次性预加载 role + roles.permissions，便于登录响应直接使用。
     *
     * @return self  支持链式调用
     */
    public function loadFullAuthContext(): self
    {
        return $this->load(['role.permissions', 'roles.permissions']);
    }

    /**
     * 推导兼容的"单一角色 code"。
     *
     * 优先级：
     *   1) 通过 users.role_id 关联的主角色
     *   2) user_roles pivot 中的第一个角色
     *   3) 空字符串
     *
     * 注意：$this->role 同时是关联方法名，也是遗留字符串列；本实现
     * 通过读取属性包避免歧义。
     *
     * @return string  小写角色 code，可能为空串
     */
    public function normalizedRole(): string
    {
        return $this
            ->effectiveRoles()
            ->first()?->code ?? '';
    }

    public function effectiveRoles(): \Illuminate\Support\Collection
    {
        $this->loadMissing(['role.permissions', 'roles.permissions']);

        return collect([$this->role])
            ->merge($this->roles)
            ->filter(fn ($role) => $role && (int) $role->status === 1)
            ->unique('id')
            ->values();
    }

    /**
     * 获取用户实际持有的全部角色 code（去重后）。
     *
     * @return array<int, string>  例如 ['super_admin', 'admin']
     */
    public function effectiveRoleCodes(): array
    {
        return $this
            ->effectiveRoles()
            ->pluck('code')
            ->all();
    }
}
