<?php

namespace App\Dao;

use App\Models\BusinessOperationLog;
use App\Models\PaypalAccount;
use App\Models\SystemState;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** PayPal 修改日志查询：在数据库中合并历史共享日志与新系统日志。 */
class PaypalOperationLogDao
{
    /**
     * 按操作时间倒序分页，历史日志也参与数据库分页。
     *
     * @param int $page 页码，从 1 开始
     * @param int $perPage 每页条数，默认 20
     * @return LengthAwarePaginator<\stdClass> 当前页原始日志及总条数
     */
    public function page(int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 按与列表一致的顺序逐条读取全部日志，不受分页参数限制。
     *
     * @return iterable<\stdClass> 本地和历史日志的完整导出游标
     */
    public function all(): iterable
    {
        return $this->query()->cursor();
    }

    /**
     * 读取补全日志所需的账户身份，停用和软删除账户仍保留历史名称。
     *
     * @return Collection<int, PaypalAccount> 仅包含 ID、邮箱和账号名的账户集合
     */
    public function accounts(): Collection
    {
        return PaypalAccount::withTrashed()->orderBy('id')->get(['id', 'email', 'account_name']);
    }

    /**
     * 读取日志操作人资料，保留已离职或软删除用户的姓名。
     *
     * @return Collection<int, User> 仅包含 ID、姓名和登录账号的用户集合
     */
    public function operators(): Collection
    {
        return User::withTrashed()->get(['id', 'display_name', 'username']);
    }

    /**
     * 合并两种日志来源，以来源前缀及序号提供稳定且不冲突的记录键。
     *
     * @return Builder 可继续分页或流式导出的查询，缺失时间的历史记录排在最后
     */
    private function query(): Builder
    {
        $local = BusinessOperationLog::query()
            ->where('module', 'paypal')
            ->selectRaw("'local:' || id::text AS id, created_at::timestamptz AS sort_time, to_jsonb(business_operation_logs) AS payload")
            ->toBase();

        // 历史共享状态保留原始 JSON；展开后再 LIMIT/OFFSET，避免整份日志传给前端。
        $legacy = SystemState::query()
            ->where('key', 'paypal_legacy_state')
            ->crossJoin(DB::raw("LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(value->'changeLogs') = 'array' THEN value->'changeLogs' ELSE '[]'::jsonb END) WITH ORDINALITY AS legacy(entry, position)"))
            ->selectRaw("'legacy:' || position::text AS id, NULLIF(entry->>'createdAt', '')::timestamptz AS sort_time, entry AS payload")
            ->toBase();

        return DB::query()->fromSub($local->unionAll($legacy), 'paypal_logs')
            ->orderByRaw('sort_time DESC NULLS LAST')->orderByDesc('id');
    }
}
