<?php

namespace App\Dao;

use App\Models\CollectorChunk;
use App\Models\CollectorJob;
use App\Models\CollectorSchedulerState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/** 从 saveb-api 当前数据库读取采集状态，防止代理连接错库后误报成功。 */
class CollectorDao
{
    /**
     * 检查采集任务表与调度心跳表是否存在。
     *
     * @return bool 任务表与调度心跳表均存在时为 true
     */
    public function installed(): bool
    {
        $schema = config('collector.schema', 'collector');

        return Schema::hasTable($schema . '.jobs') && Schema::hasTable($schema . '.scheduler_state');
    }

    /**
     * 构建当前账户已开启 API 发布且非预览的采集任务查询。
     *
     * @return Builder<CollectorJob> 已应用上述条件的查询构造器，可继续追加查询
     */
    private function publishedJobs(): Builder
    {
        return CollectorJob::where('account', config('collector.account'))
            ->whereIn('mode', ['refresh', 'today'])
            ->whereRaw("context->>'publish_api' = 'true'")
            ->whereRaw("coalesce(params->>'dry_run','false') = 'false'");
    }

    /**
     * 读取最近创建的已发布采集任务。
     *
     * @return CollectorJob|null 采集运行状态模型实例；未找到时返回 null
     */
    public function latest(): ?CollectorJob
    {
        return $this->publishedJobs()->orderByDesc('created_at')->first();
    }

    /**
     * 读取最早排队、运行或重试中的已发布采集任务。
     *
     * @return CollectorJob|null 采集运行状态模型实例；未找到时返回 null
     */
    public function active(): ?CollectorJob
    {
        return $this->publishedJobs()->whereIn('status', ['queued', 'running', 'retrying'])->orderBy('created_at')->first();
    }

    /**
     * 用实际提交的分片判断进度，任务运行时间长不等同于 worker 停止。
     *
     * @param  string  $jobId  采集任务编号
     * @return array 采集运行状态结果数组；返回字段：total、completed、lastProgressAt
     */
    public function progress(string $jobId): array
    {
        $progress = CollectorChunk::where('job_id', $jobId)
            ->selectRaw("count(*) AS total, count(*) FILTER (WHERE status='succeeded') AS completed, max(committed_at) AS last_progress_at")
            ->first();

        return [
            'total' => (int) $progress->total,
            'completed' => (int) $progress->completed,
            'lastProgressAt' => $progress->last_progress_at,
        ];
    }

    /**
     * 读取最近成功完成的已发布采集任务。
     *
     * @return CollectorJob|null 采集运行状态模型实例；未找到时返回 null
     */
    public function lastSuccess(): ?CollectorJob
    {
        return $this->publishedJobs()->where('status', 'succeeded')->orderByDesc('updated_at')->first();
    }

    /**
     * 读取当前采集账户的调度心跳。
     *
     * @return CollectorSchedulerState|null 采集运行状态模型实例；未找到时返回 null
     */
    public function scheduler(): ?CollectorSchedulerState
    {
        return CollectorSchedulerState::find(config('collector.account'));
    }

    /**
     * 按任务编号查找当前账户已发布的采集任务。
     *
     * @param  string  $id  采集任务编号
     * @return CollectorJob|null 采集运行状态模型实例；未找到时返回 null
     */
    public function findPublished(string $id): ?CollectorJob
    {
        return $this->publishedJobs()->whereKey($id)->first();
    }
}
