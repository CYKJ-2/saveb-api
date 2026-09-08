<?php

namespace App\Models;

/**
 * 每日业务统计，对应 daily_stats 表。
 *
 * @property int $id 主键
 * @property \Carbon\CarbonInterface $stat_date 业务统计日
 * @property string $channel 渠道维度
 * @property string $staff_code 员工维度（空串 = 汇总口径）
 * @property string $orders_count 按份额计算的订单数
 * @property string $items_count 按份额计算的件数
 * @property string $usd_amount 按份额计算的美元金额
 * @property \Carbon\CarbonInterface $updated_at 汇总刷新时间
 * @property \Carbon\CarbonInterface $created_at 创建时间（v3 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v3 新增）
 */
class DailyStat extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'daily_stats';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'stat_date' => 'date',
        'updated_at' => 'datetime',
        'created_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
