<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 采集任务；只读采集器事务写入的状态，不使用业务表的软删除作用域。 */
class CollectorJob extends Model
{
    public function getTable(): string
    {
        return config('collector.schema', 'collector') . '.jobs';
    }

    protected $table = 'collector.jobs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['params' => 'array', 'context' => 'array', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
}
