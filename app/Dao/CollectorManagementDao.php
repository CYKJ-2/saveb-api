<?php

namespace App\Dao;

use App\Models\CollectorChunk;
use App\Models\CollectorJob;
use App\Models\CollectorSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollectorManagementDao
{
    /**
     * 检查采集管理所需的数据表是否存在。
     *
     * @return bool 当前 schema 的自动采集调度表存在时为 true
     */
    public function installed(): bool
    {
        return Schema::hasTable((new CollectorSchedule())->getTable());
    }

    /**
     * 读取当前账户的自动采集调度配置。
     *
     * @return CollectorSchedule|null 采集任务模型实例；未找到时返回 null
     */
    public function schedule(): ?CollectorSchedule
    {
        return $this->installed() ? CollectorSchedule::find(config('collector.account')) : null;
    }

    /**
     * 保存后从保存时间重新计时，不修改正在运行的任务。
     *
     * @param  int  $minutes  自动采集间隔，单位分钟
     * @param  int  $userId  用户主键 ID
     * @return CollectorSchedule 采集任务模型实例
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 指定业务记录不存在
     */
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

    /**
     * 按任务编号读取当前采集账户的任务。
     *
     * @param  string  $id  采集任务编号
     * @return CollectorJob|null 采集任务模型实例；未找到时返回 null
     */
    public function findJob(string $id): ?CollectorJob
    {
        return CollectorJob::where('account', config('collector.account'))->whereKey($id)->first();
    }

    /**
     * 分页查询当前采集账户的任务摘要。
     *
     * @param  int  $page  页码，从 1 开始
     * @param  int  $perPage  每页条数；默认 20
     * @return array 采集任务结果数组；返回字段：items、total、page、pageSize
     */
    public function jobs(int $page, int $perPage = 20): array
    {
        $jobs = CollectorJob::where('account', config('collector.account'))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $jobs->getCollection()->map(fn (CollectorJob $job) => $this->summary($job))->all(),
            'total' => $jobs->total(), 'page' => $jobs->currentPage(), 'pageSize' => $perPage,
        ];
    }

    /**
     * 序列化采集任务的模式、状态、进度与时间字段。
     *
     * @param  CollectorJob  $job  采集任务模型
     * @return array 采集任务结果数组；返回字段：jobId、mode、status、actor、error、params、publication、createdAt、completedAt、total、completed
     */
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

    /**
     * 分页读取任务分片及其提交状态。
     *
     * @param  string  $jobId  采集任务编号
     * @param  int  $page  页码，从 1 开始
     * @param  int  $perPage  每页条数；默认 20
     * @return array 采集任务结果数组；返回字段：items、total、page、pageSize
     */
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
