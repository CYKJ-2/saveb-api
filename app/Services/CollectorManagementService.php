<?php

namespace App\Services;

use App\Dao\CollectorManagementDao;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 动态调度与范围任务管理；来源凭据和服务令牌始终留在服务端。 */
class CollectorManagementService
{
    /**
     * 注入 采集任务处理所需的依赖。
     *
     * @param  CollectorManagementDao  $collectorManagementDao  采集任务数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private CollectorManagementDao $collectorManagementDao)
    {
    }

    /**
     * 检查采集管理数据表是否存在，缺失时中止请求。
     *
     * @return void 无返回值；副作用见方法说明
     * @see CollectorManagementDao::installed()
     */
    private function requireInstalled(): void
    {
        if (!$this->collectorManagementDao->installed()) {
            throw new HttpException(503, '请先执行采集器数据库迁移');
        }
    }

    /**
     * 读取自动采集间隔与下次执行时间。
     *
     * @return array intervalMinutes、nextRunAt、updatedAt、updatedBy 调度配置
     * @see CollectorManagementDao::schedule()
     */
    public function settings(): array
    {
        $this->requireInstalled();
        $schedule = $this->collectorManagementDao->schedule();

        return [
            'intervalMinutes' => $schedule?->interval_minutes ?? 30,
            'nextRunAt' => $schedule?->next_run_at?->toIso8601String(),
            'updatedAt' => $schedule?->updated_at?->toIso8601String(),
            'updatedBy' => $schedule?->updated_by,
        ];
    }

    /**
     * 保存自动采集间隔并重新计算下次执行时间。
     *
     * @param  int  $minutes  自动采集间隔，单位分钟
     * @param  int  $userId  用户主键 ID
     * @return array 更新后的 intervalMinutes、nextRunAt、updatedAt、updatedBy
     * @see CollectorManagementDao::saveSchedule()
     */
    public function saveSettings(int $minutes, int $userId): array
    {
        $this->requireInstalled();
        $this->collectorManagementDao->saveSchedule($minutes, $userId);

        return $this->settings();
    }

    /**
     * 分页读取当前账户的采集任务列表。
     *
     * @param  int  $page  页码，从 1 开始
     * @param  int  $perPage  每页条数；默认 20
     * @return array 当前页记录及分页信息；汇总字段按业务方法计算
     * @see CollectorManagementDao::jobs()
     */
    public function jobs(int $page, int $perPage = 20): array
    {
        $this->requireInstalled();

        return $this->collectorManagementDao->jobs($page, $perPage);
    }

    /**
     * 读取采集任务摘要及分页分片进度。
     *
     * @param  string  $id  采集任务编号
     * @param  int  $page  页码，从 1 开始
     * @param  int  $perPage  每页条数；默认 20
     * @return array 任务摘要及 chunks 分页分片结果
     * @see CollectorManagementDao::findJob()
     * @see CollectorManagementDao::summary()
     * @see CollectorManagementDao::chunks()
     */
    public function detail(string $id, int $page, int $perPage = 20): array
    {
        $this->requireInstalled();
        $job = $this->collectorManagementDao->findJob($id);
        if (!$job) {
            throw new HttpException(404, '采集任务不存在');
        }

        return [...$this->collectorManagementDao->summary($job), 'chunks' => $this->collectorManagementDao->chunks($id, $page, $perPage)];
    }

    /**
     * 提交采集任务并核对账户、模式和数据库发布状态。
     *
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 mode、dryRun、start、end、sourceJobId、requestId
     * @param  int  $userId  用户主键 ID
     * @return array 已核对入库的任务摘要，包括 jobId、mode、status 和进度
     * @see CollectorManagementDao::findJob()
     * @see CollectorManagementDao::summary()
     */
    public function submit(array $data, int $userId): array
    {
        $this->requireInstalled();
        if (!config('collector.url') || !config('collector.token')) {
            throw new HttpException(503, '采集服务尚未配置');
        }
        $preview = $data['mode'] === 'reprocess' || ($data['dryRun'] ?? false);
        $payload = ['mode' => $data['mode'], 'start' => $data['start'], 'end' => $data['end'], 'dryRun' => $preview];
        if ($data['mode'] === 'reprocess') {
            if (!$this->collectorManagementDao->findJob($data['sourceJobId'])) {
                throw new HttpException(422, '来源归档任务不存在或不属于当前账户');
            }
            $payload['sourceJobId'] = $data['sourceJobId'];
        }
        try {
            $response = Http::acceptJson()->withToken(config('collector.token'))
                ->withHeaders(['Idempotency-Key' => $data['requestId'], 'X-Collector-Actor' => 'saveb-api:' . $userId])
                ->connectTimeout(3)->timeout(20)->withoutRedirecting()
                ->post(rtrim(config('collector.url'), '/') . '/api/collect/jobs', $payload);
        } catch (ConnectionException $exception) {
            throw new HttpException(503, '提交超时，请保留请求编号重试');
        }
        if (!$response->successful()) {
            throw new HttpException(
                $response->status() === 400 || $response->status() === 422 ? 422 : 503,
                '采集器未受理任务，请核对日期、归档任务及服务状态',
            );
        }
        $id = $response->json('jobId');
        $job = is_string($id) ? $this->collectorManagementDao->findJob($id) : null;
        if (!$job || $job->actor !== 'saveb-api:' . $userId || $job->mode !== $data['mode']
            || (bool) ($job->params['dry_run'] ?? false) !== $preview
            || (!$preview && !($job->context['publish_api'] ?? false))) {
            throw new HttpException(503, '任务发布模式或目标数据库不匹配');
        }

        return $this->collectorManagementDao->summary($job);
    }
}
