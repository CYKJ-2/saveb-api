<?php

namespace App\Dao;

use App\Models\PaypalAccount;
use App\Models\PaypalBalanceEntry;
use App\Models\PaypalReview;
use App\Models\PaypalWithdrawal;
use Illuminate\Database\Eloquent\Collection;

/**
 * PayPal 账户数据访问：封装模型查询与持久化操作。
 */
class PaypalDao
{
    /**
     * 查询提款流水，日期范围包含起止日。
     */
    public function withdrawals(array $filters): Collection
    {
        return PaypalWithdrawal::query()
            ->with('account')
            ->whereHas('account', fn ($query) => $query->where('active', true))
            ->when($filters['keyword'] ?? '', function ($query, $keyword) {
                $query->whereHas('account', function ($accounts) use ($keyword) {
                    $accounts->where(function ($names) use ($keyword) {
                        $names->where('account_name', 'ilike', '%' . $keyword . '%')
                            ->orWhere('email', 'ilike', '%' . $keyword . '%');
                    });
                });
            })
            ->when($filters['startDate'] ?? '', fn ($query, $date) => $query->whereDate('withdrawn_at', '>=', $date))
            ->when($filters['endDate'] ?? '', fn ($query, $date) => $query->whereDate('withdrawn_at', '<=', $date))
            ->orderByDesc('withdrawn_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 读取记录集合。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaypalAccount>
     */
    public function all(): Collection
    {
        return PaypalAccount::where('active', true)
            ->with(['balances', 'reviews', 'withdrawals'])
            ->orderBy('id')
            ->get();
    }

    /**
     * 加行锁读取记录。
     */
    public function lock(int $id): PaypalAccount
    {
        return PaypalAccount::whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * 创建记录。
     */
    public function create(array $data): PaypalAccount
    {
        return PaypalAccount::create($data);
    }

    /**
     * 汇总提现金额。
     */
    public function withdrawn(int $id): float
    {
        return (float) PaypalWithdrawal::where('account_id', $id)->sum('amount');
    }

    /**
     * 保存记录。
     */
    public function save(PaypalAccount $row, array $data): void
    {
        $row
            ->fill($data)
            ->save();
    }

    /**
     * 登记账户余额。
     */
    public function balance(
        int $id,
        float $amount,
        int $actor,
    ): void {
        PaypalBalanceEntry::create([
            'account_id' => $id,
            'balance' => $amount,
            'entered_by' => $actor,
        ]);
    }

    /**
     * 登记审核次数。
     */
    public function review(
        int $id,
        int $value,
        int $actor,
    ): void {
        PaypalReview::create([
            'account_id' => $id,
            'review_count' => $value,
            'entered_by' => $actor,
        ]);
    }

    /**
     * 登记提现记录。
     */
    public function withdrawal(
        int $id,
        array $data,
        int $actor,
    ): void {
        PaypalWithdrawal::create([
            'account_id' => $id,
            'amount' => $data['amount'],
            'withdrawn_at' => $data['date'],
            'source' => $data['source'] ?? '',
            'created_by' => $actor,
        ]);
    }
}
