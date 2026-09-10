<?php

namespace App\Services;

use App\Dao\CollectorDao;
use App\Dao\CollectorManagementDao;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 首页采集监控和当天采集编排；浏览器不接触来源 Cookie 或采集器令牌。 */
class CollectorService
{
    /**
     * 注入 采集运行状态处理所需的依赖。
     *
     * @param  CollectorDao  $collectorDao  采集运行状态数据访问对象
     * @param  CollectorManagementDao  $collectorManagementDao  采集任务数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private CollectorDao $collectorDao, private CollectorManagementDao $collectorManagementDao)
    {
    }

    /**
     * 读取当前数据库的采集任务、调度心跳及执行进度。
     *
     * @return array 采集服务可用性、调度心跳、当前任务及最近成功任务等状态
     * @see CollectorDao::installed()
     * @see CollectorDao::latest()
     * @see CollectorDao::active()
     * @see CollectorDao::lastSuccess()
     * @see CollectorDao::scheduler()
     * @see CollectorManagementDao::schedule()
     * @see CollectorDao::progress()
     */
    public function status(): array
    {
        $now = now('Asia/Shanghai');
        $result = [
            'state' => 'unknown', 'lastSuccessAt' => null, 'lastError' => null,
            'currentDate' => $now->toDateString(), 'nextAttemptAt' => null,
            'staleWarningMinutes' => 30, 'intervalMinutes' => 30,
            'schedulerHealthy' => false, 'schedulerLastAttemptAt' => null,
            'activeJobId' => null, 'jobStatus' => null, 'lastSuccessJobId' => null,
            'completedChunks' => 0, 'totalChunks' => 0, 'lastProgressAt' => null,
            'canTrigger' => $this->configured(),
        ];
        if (!$this->collectorDao->installed()) {
            return [...$result, 'lastError' => '采集器尚未初始化当前数据库', 'canTrigger' => false];
        }
        $latest = $this->collectorDao->latest();
        $active = $this->collectorDao->active();
        $success = $this->collectorDao->lastSuccess();
        $scheduler = $this->collectorDao->scheduler();
        $schedule = $this->collectorManagementDao->schedule();
        $interval = $schedule?->interval_minutes ?? 30;
        $healthy = $scheduler && !$scheduler->error && $scheduler->last_success_at
            && $scheduler->last_success_at->greaterThanOrEqualTo($now->copy()->subMinutes($schedule ? 5 : 35));
        $next = $now->copy()->startOfHour()->addMinutes($now->minute < 30 ? 30 : 60);
        $result = [...$result,
            'intervalMinutes' => $interval, 'staleWarningMinutes' => $interval,
            'lastSuccessAt' => $success?->updated_at?->toIso8601String(),
            'lastSuccessJobId' => $success?->id,
            'schedulerHealthy' => (bool) $healthy,
            'schedulerLastAttemptAt' => $scheduler?->last_attempt_at?->toIso8601String(),
            'nextAttemptAt' => $healthy ? ($schedule?->next_run_at ?? $next)->toIso8601String() : null,
            'activeJobId' => $active?->id, 'jobStatus' => $active?->status ?? $latest?->status,
        ];
        if ($active) {
            $progress = $this->collectorDao->progress($active->id);
            $lastProgress = $progress['lastProgressAt']
                ? CarbonImmutable::parse($progress['lastProgressAt']) : $active->created_at;
            $stalled = $lastProgress->lessThan($now->copy()->subMinutes(35));

            return [...$result, 'state' => $stalled ? 'stale' : 'running',
                'completedChunks' => $progress['completed'], 'totalChunks' => $progress['total'],
                'lastProgressAt' => $progress['lastProgressAt'] ? $lastProgress->toIso8601String() : null,
                'lastError' => $stalled ? '采集任务连续 35 分钟没有分片完成，请检查 worker 和队列' : null];
        }
        if ($latest && in_array($latest->status, ['failed', 'partial_failed', 'cancelled'])) {
            return [...$result, 'state' => 'failed', 'lastError' => $latest->error ?: $latest->status];
        }
        if (!$healthy) {
            return [...$result, 'state' => 'stale', 'lastError' => '定时调度未运行、心跳过期或检查失败'];
        }
        if (!$success || $success->updated_at->lessThan($now->copy()->subMinutes($interval))) {
            return [...$result, 'state' => 'stale', 'lastError' => '超过 ' . $interval . ' 分钟没有成功采集记录'];
        }

        return [...$result, 'state' => 'success'];
    }

    /**
     * 检查采集服务地址与访问令牌是否已配置。
     *
     * @return bool 采集服务地址和令牌均已配置时为 true
     */
    private function configured(): bool
    {
        return (bool) config('collector.url') && (bool) config('collector.token');
    }

    /**
     * 提交当日采集任务，并核对任务已发布到当前数据库。
     *
     * @param  int  $userId  用户主键 ID
     * @param  string  $idempotencyKey  幂等请求编号；重试同一次操作时沿用原值
     * @return array 采集运行状态结果数组；返回字段：jobId、status、date
     * @see CollectorDao::installed()
     * @see CollectorDao::findPublished()
     */
    public function collectToday(int $userId, string $idempotencyKey): array
    {
        if (!$this->configured() || !$this->collectorDao->installed()) {
            throw new HttpException(503, '采集服务尚未配置或数据库尚未初始化');
        }
        try {
            $response = Http::acceptJson()->withToken(config('collector.token'))
                ->withHeaders(['Idempotency-Key' => $idempotencyKey, 'X-Collector-Actor' => 'saveb-api:' . $userId])
                ->connectTimeout(3)->timeout(20)->withoutRedirecting()
                ->post(rtrim(config('collector.url'), '/') . '/api/collect/jobs', ['mode' => 'today']);
        } catch (ConnectionException $exception) {
            throw new HttpException(503, '采集服务连接超时，请稍后重试（保留本次请求编号）');
        }
        if (!$response->successful()) {
            throw new HttpException(503, '采集服务拒绝请求，请检查服务配置、Cookie 和采集日志');
        }
        $id = $response->json('jobId');
        $job = is_string($id) ? $this->collectorDao->findPublished($id) : null;
        if (!$job || $job->actor !== 'saveb-api:' . $userId || $job->mode !== 'today') {
            throw new HttpException(503, '采集任务未写入当前 API 数据库，请检查采集器数据库地址及 SAVEB_PUBLISH_API');
        }

        return ['jobId' => $job->id, 'status' => $job->status, 'date' => now('Asia/Shanghai')->toDateString()];
    }
}
