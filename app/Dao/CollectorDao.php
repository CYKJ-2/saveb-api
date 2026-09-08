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
    public function installed(): bool
    {
        $schema = config('collector.schema', 'collector');

        return Schema::hasTable($schema . '.jobs') && Schema::hasTable($schema . '.scheduler_state');
    }

    /** @return Builder<CollectorJob> */
    private function publishedJobs(): Builder
    {
        return CollectorJob::where('account', config('collector.account'))
            ->whereIn('mode', ['refresh', 'today'])
            ->whereRaw("context->>'publish_api' = 'true'")
            ->whereRaw("coalesce(params->>'dry_run','false') = 'false'");
    }

    public function latest(): ?CollectorJob
    {
        return $this->publishedJobs()->orderByDesc('created_at')->first();
    }

    public function active(): ?CollectorJob
    {
        return $this->publishedJobs()->whereIn('status', ['queued', 'running', 'retrying'])->orderBy('created_at')->first();
    }

    /** 用实际提交的分片判断进度，任务运行时间长不等同于 worker 停止。 */
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

    public function lastSuccess(): ?CollectorJob
    {
        return $this->publishedJobs()->where('status', 'succeeded')->orderByDesc('updated_at')->first();
    }

    public function scheduler(): ?CollectorSchedulerState
    {
        return CollectorSchedulerState::find(config('collector.account'));
    }

    public function findPublished(string $id): ?CollectorJob
    {
        return $this->publishedJobs()->whereKey($id)->first();
    }
}
