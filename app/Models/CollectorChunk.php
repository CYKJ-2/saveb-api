<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 仅查询分片进度，管理接口不返回客户原始归档。 */
class CollectorChunk extends Model
{
    /**
     * 根据采集 schema 配置返回完整数据表名。
     *
     * @return string 包含 schema 前缀的数据表名
     */
    public function getTable(): string
    {
        return config('collector.schema', 'collector') . '.chunks';
    }

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['scope' => 'array', 'counts' => 'array', 'committed_at' => 'immutable_datetime'];
}
