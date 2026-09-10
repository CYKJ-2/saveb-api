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
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 keyword、startDate、endDate
     * @return Collection PayPal 账户查询或计算结果集合；无匹配时为空集合
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
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaypalAccount> PayPal 账户查询或计算结果集合；无匹配时为空集合
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
     *
     * @param  int  $id  PayPal 账户记录主键 ID
     * @return PaypalAccount PayPal 账户模型实例
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 指定业务记录不存在
     */
    public function lock(int $id): PaypalAccount
    {
        return PaypalAccount::whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * 创建 PayPal 账户记录。
     *
     * @param  array  $data  经过 Controller 校验的业务字段
     * @return PaypalAccount PayPal 账户模型实例
     */
    public function create(array $data): PaypalAccount
    {
        return PaypalAccount::create($data);
    }

    /**
     * 汇总提现金额。
     *
     * @param  int  $id  PayPal 账户记录主键 ID
     * @return float PayPal 账户计算所得金额
     */
    public function withdrawn(int $id): float
    {
        return (float) PaypalWithdrawal::where('account_id', $id)->sum('amount');
    }

    /**
     * 保存 PayPal 账户及其关联数据。
     *
     * @param  PaypalAccount  $row  PayPal 账户单条记录
     * @param  array  $data  经过 Controller 校验的业务字段
     * @return void 无返回值；副作用见方法说明
     */
    public function save(PaypalAccount $row, array $data): void
    {
        $row
            ->fill($data)
            ->save();
    }

    /**
     * 登记账户余额。
     *
     * @param  int  $id  PayPal 账户记录主键 ID
     * @param  float  $amount  当前计算或登记的金额
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return void 无返回值；副作用见方法说明
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
     *
     * @param  int  $id  PayPal 账户记录主键 ID
     * @param  int  $value  待归一化的原始值
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return void 无返回值；副作用见方法说明
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
     *
     * @param  int  $id  PayPal 账户记录主键 ID
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 amount、date、source
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return void 无返回值；副作用见方法说明
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
