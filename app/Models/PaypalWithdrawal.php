<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PayPal 提现记录模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property int $account_id FK → paypal_accounts.id（级联）
 * @property string $amount 提现金额
 * @property string|null $source 提现来源/备注
 * @property string $withdrawn_at 提现业务日期
 * @property int|null $created_by 创建用户（bigint）
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class PaypalWithdrawal extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'paypal_withdrawals';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /**
     * 提款所属账户，用于记录列表的账号名与邮箱。
     *
     * @return BelongsTo<PaypalAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PaypalAccount::class, 'account_id');
    }
}
