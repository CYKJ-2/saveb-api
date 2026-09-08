<?php

namespace App\Dao;

use App\Models\ApiToken;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * API token 数据访问对象。
 *
 * token 在表中只保存 SHA-256 哈希，原始明文仅在签发时返回一次。
 *
 * @extends BaseDao<ApiToken>
 */
class ApiTokenDao extends BaseDao
{
    /**
     * 返回当前 DAO 关联的模型类。
     *
     * @return class-string<ApiToken>
     */
    protected function model(): string
    {
        return ApiToken::class;
    }

    /**
     * 按明文 token 查找有效（未过期）的 token 行。
     * 不存在或已过期均返回 null。
     *
     * @param  string  $plain  Bearer token 明文
     * @return ApiToken|null
     */
    public function findValidByPlain(string $plain): ?ApiToken
    {
        $hash = hash('sha256', $plain);

        return $this
            ->query()
            ->where('token_hash', $hash)
            ->where(function ($query) {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })
            ->first();
    }

    /**
     * 获取指定用户所有有效 token。
     *
     * @param  int  $userId  用户主键 ID
     * @return Collection<int, ApiToken>
     */
    public function findByUser(int $userId): Collection
    {
        return $this
            ->query()
            ->where('user_id', $userId)
            ->where(function ($query) {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * 物理删除（不软删除）某用户的所有 token。
     *
     * @param  int  $userId  用户主键 ID
     * @return int           受影响行数
     */
    public function deleteByUser(int $userId): int
    {
        return $this->deleteWhere(['user_id' => $userId]);
    }

    /**
     * 按明文 token 直接删除对应行（登出用）。
     *
     * @param  string  $plain  Bearer token 明文
     * @return int             受影响行数
     */
    public function deleteByPlain(string $plain): int
    {
        $hash = hash('sha256', $plain);

        return $this->deleteWhere(['token_hash' => $hash]);
    }

    /**
     * 清理已过期的 token 记录（批量回收）。
     *
     * @return int  受影响行数
     */
    public function pruneExpired(): int
    {
        return $this
            ->query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->delete();
    }

    /**
     * 统计某用户的有效 token 数量。
     *
     * @param  int  $userId  用户主键 ID
     * @return int           有效 token 数
     */
    public function countByUser(int $userId): int
    {
        return $this->count(['user_id' => $userId]);
    }

    /**
     * 刷新 token 的 last_used_at 字段为当前时间。
     *
     * @param  int  $tokenId  token 主键 ID
     * @return int            受影响行数
     */
    public function touch(int $tokenId): int
    {
        return $this->updateWhere(['id' => $tokenId], ['last_used_at' => Carbon::now()]);
    }
}
