<?php

namespace App\Dao;

use App\Models\CollectorJob;

class LogisticsDao
{
    public function find(string $jobId): ?CollectorJob
    {
        return CollectorJob::where('account', config('collector.account'))
            ->where('mode', 'logistics')->where('id', $jobId)->first();
    }
}
