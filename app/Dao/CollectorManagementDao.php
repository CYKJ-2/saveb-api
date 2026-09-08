<?php

namespace App\Dao;

use App\Models\CollectorChunk;
use App\Models\CollectorJob;
use App\Models\CollectorSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollectorManagementDao
{
    public function installed(): bool
    {
        return Schema::hasTable((new CollectorSchedule())->getTable());
    }

    public function schedule(): ?CollectorSchedule
    {
        return $this->installed() ? CollectorSchedule::find(config('collector.account')) : null;
    }

    /** 保存后从保存时间重新计时，不修改正在运行的任务。 */
    public function saveSchedule(int $minutes, int $userId): CollectorSchedule
    {
        return DB::transaction(function () use ($minutes, $userId) {
            $account = config('collector.account');
            CollectorSchedule::insertOrIgnore(['account' => $account]);
            $schedule = CollectorSchedule::whereKey($account)->lockForUpdate()->firstOrFail();
            $schedule->fill([
                'interval_minutes' => $minutes,
                'next_run_at' => now()->addMinutes($minutes),
                'updated_by' => 'saveb-api:' . $userId,
                'updated_at' => now(),
            ])->save();

            return $schedule;
        });
    }

    public function findJob(string $id): ?CollectorJob
    {
        return CollectorJob::where('account', config('collector.account'))->whereKey($id)->first();
    }

    public function jobs(int $page, int $perPage = 20): array
    {
        $jobs = CollectorJob::where('account', config('collector.account'))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $jobs->getCollection()->map(fn (CollectorJob $job) => $this->summary($job))->all(),
            'total' => $jobs->total(), 'page' => $jobs->currentPage(), 'pageSize' => $perPage,
        ];
    }

    public function summary(CollectorJob $job): array
    {
        $counts = CollectorChunk::where('job_id', $job->id)->select('status')->selectRaw('count(*) AS total')->groupBy('status')->get();

        return [
            'jobId' => $job->id, 'mode' => $job->mode, 'status' => $job->status,
            'actor' => $job->actor, 'error' => $job->error, 'params' => $job->params,
            'publication' => ($job->params['dry_run'] ?? false) ? 'preview' : (($job->context['publish_api'] ?? false) ? 'saveb-api' : 'shadow'),
            'createdAt' => $job->created_at?->toIso8601String(),
            'completedAt' => in_array($job->status, ['succeeded', 'failed', 'partial_failed', 'cancelled']) ? $job->updated_at?->toIso8601String() : null,
            'total' => $counts->sum('total'), 'completed' => $counts->where('status', 'succeeded')->sum('total'),
        ];
    }

    public function chunks(string $jobId, int $page, int $perPage = 20): array
    {
        $chunks = CollectorChunk::where('job_id', $jobId)->orderBy('id')->paginate(
            $perPage,
            ['id', 'scope', 'status', 'attempts', 'error', 'counts', 'committed_at'],
            'page',
            $page,
        );

        return ['items' => $chunks->items(), 'total' => $chunks->total(), 'page' => $page, 'pageSize' => $perPage];
    }
}
