<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 每个来源账户的定时调度心跳，与手动采集的成功时间分别监控。 */
class CollectorSchedulerState extends Model
{
    /**
     * 根据采集 schema 配置返回完整数据表名。
     *
     * @return string 包含 schema 前缀的数据表名
     */
    public function getTable(): string
    {
        return config('collector.schema', 'collector') . '.scheduler_state';
    }

    protected $table = 'collector.scheduler_state';

    protected $primaryKey = 'account';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['last_attempt_at' => 'immutable_datetime', 'last_success_at' => 'immutable_datetime'];
}
