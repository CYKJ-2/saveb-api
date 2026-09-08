<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * API token 实体模型，对应 api_tokens 表。
 *
 * 设计要点：
 *   - 仅保存 SHA-256 哈希，原始明文 token 仅在签发时返回一次
 *   - token 明文格式：saveb_ 前缀 + 40 位随机字符串（总长 46）
 *   - supports 软删除（deleted_at）；过期 token 由定时任务清理
 *
 * @property int         $id
 * @property int         $user_id          所属用户 ID
 * @property string      $token_hash       token 明文 SHA-256 哈希值（唯一）
 * @property string|null $name             token 标签，便于在用户中心辨识
 * @property array|null  $abilities        token 权限范围，'*' 表示全部
 * @property string|null $ip               签发时客户端 IP
 * @property string|null $user_agent       签发时 UA
 * @property Carbon|null $last_used_at     最近一次使用时间
 * @property Carbon|null $expires_at       过期时间；NULL=永不过期
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 * @property Carbon|null $deleted_at
 * @property-read \App\Models\User|null $user
 */
class ApiToken extends BaseModel
{
    /** @var string 表名 */
    protected $table = 'api_tokens';

    /** @var array<int, string> 可批量赋值字段 */
    protected $fillable = [
        'user_id',
        'token_hash',
        'name',
        'abilities',
        'ip',
        'user_agent',
        'last_used_at',
        'expires_at',
    ];

    /**
     * 字段类型转换。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * token 所属用户。
     *
     * @return BelongsTo<User, ApiToken>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 为指定用户签发一个新的 API token。
     * 仅此处会返回 token 明文，调用方需自行保证只暴露一次。
     *
     * 明文格式：saveb_ 前缀 + 40 位随机字符串（总长 46）。
     *
     * @param  int         $userId             用户主键 ID
     * @param  string|null $name               token 标签
     * @param  string|null $ip                 客户端 IP
     * @param  string|null $userAgent          客户端 UA
     * @param  int         $expiresInSeconds   有效期秒数
     * @param  array       $abilities          权限范围数组，['*'] 表示全部
     * @return array{id: int, plain: string, token_hash: string, expires_at: Carbon}
     */
    public static function issue(
        int $userId,
        ?string $name = null,
        ?string $ip = null,
        ?string $userAgent = null,
        int $expiresInSeconds = 86400 * 7,
        array $abilities = ['*'],
    ): array {
        $plain = 'saveb_' . Str::random(40);
        $hash = hash('sha256', $plain);
        $row = self::create([
            'user_id' => $userId,
            'token_hash' => $hash,
            // UA 截断到 255 字符，避免超长字段值写入失败
            'name' => $name !== null ? mb_strimwidth($name, 0, 255, '') : null,
            'abilities' => $abilities,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'expires_at' => now()->addSeconds($expiresInSeconds),
        ]);

        return [
            'id' => $row->id,
            'plain' => $plain,
            'token_hash' => $hash,
            'expires_at' => $row->expires_at,
        ];
    }

    /**
     * 通过 token 明文查找对应行。过期或不存在返回 null。
     *
     * @param  string   $plain  Bearer token 明文
     * @return self|null        命中的 token 行；过期/不存在时返回 null
     */
    public static function findByPlain(string $plain): ?self
    {
        $hash = hash('sha256', $plain);
        $row = self::where('token_hash', $hash)->first();
        if (!$row) {
            return null;
        }
        if ($row->expires_at && $row->expires_at->isPast()) {
            return null;
        }

        return $row;
    }

    /**
     * 判断 token 是否拥有指定权限。abilities 中包含 '*' 即视为全部允许。
     *
     * @param  string  $ability  权限标识，例如 'users.read'
     * @return bool              拥有该能力返回 true
     */
    public function can(string $ability): bool
    {
        $abilities = $this->abilities ?? [];
        if (in_array('*', $abilities, true)) {
            return true;
        }

        return in_array($ability, $abilities, true);
    }
}
