<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PayPal 账户模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property string $email PayPal 邮箱；敏感，唯一
 * @property string|null $account_name 账号显示名称
 * @property string|null $added_date 添加日期
 * @property bool $active 是否启用
 * @property array $meta 扩展元数据
 * @property \Carbon\CarbonInterface $created_at 创建时间（v4 新增）
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 * @property int $version 乐观锁版本号
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaypalBalanceEntry> $balances
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaypalReview> $reviews
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaypalWithdrawal> $withdrawals
 */
class PaypalAccount extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'paypal_accounts';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /**
     * 定义字段类型转换。
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'active' => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * 关联余额登记。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\PaypalBalanceEntry, $this>
     */
    public function balances(): HasMany
    {
        return $this
            ->hasMany(PaypalBalanceEntry::class, 'account_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * 关联审核记录。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\PaypalReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this
            ->hasMany(PaypalReview::class, 'account_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * 关联提现记录。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\PaypalWithdrawal, $this>
     */
    public function withdrawals(): HasMany
    {
        return $this
            ->hasMany(PaypalWithdrawal::class, 'account_id')
            ->orderByDesc('withdrawn_at')
            ->orderByDesc('id');
    }
}
