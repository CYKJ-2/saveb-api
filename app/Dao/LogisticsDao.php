<?php

namespace App\Dao;

use App\Models\CollectorJob;

class LogisticsDao
{
    /**
     * 读取指定编号、当前账户下的物流采集任务。
     *
     * @param  string  $jobId  采集任务编号
     * @return CollectorJob|null 物流采集模型实例；未找到时返回 null
     */
    public function find(string $jobId): ?CollectorJob
    {
        return CollectorJob::where('account', config('collector.account'))
            ->where('mode', 'logistics')->where('id', $jobId)->first();
    }
}
