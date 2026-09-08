<?php

namespace App\Models;

/**
 * 汇率模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property string $currency 币种代码
 * @property string $rate_to_usd 兑美元汇率
 * @property string $effective_date 生效日期
 * @property \Carbon\CarbonInterface $created_at 创建时间（v3 新增）
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v3 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v3 新增）
 */
class ExchangeRate extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'exchange_rates';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];
}
