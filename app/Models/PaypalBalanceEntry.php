<?php

namespace App\Models;

/**
 * PayPal 余额登记模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property int $account_id FK → paypal_accounts.id（级联）
 * @property string $balance 当次记录余额
 * @property int|null $entered_by 录入用户（bigint）
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class PaypalBalanceEntry extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'paypal_balance_entries';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];
}
