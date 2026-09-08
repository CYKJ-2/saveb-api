<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 每个来源账户的动态采集间隔，与采集器共享行锁。 */
class CollectorSchedule extends Model
{
    public function getTable(): string
    {
        return config('collector.schema', 'collector') . '.schedules';
    }

    protected $primaryKey = 'account';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['account', 'interval_minutes', 'next_run_at', 'updated_by', 'updated_at'];

    protected $casts = ['interval_minutes' => 'integer', 'next_run_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
}
