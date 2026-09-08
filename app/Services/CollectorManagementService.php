<?php

namespace App\Services;

use App\Dao\CollectorManagementDao;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 动态调度与范围任务管理；来源凭据和服务令牌始终留在服务端。 */
class CollectorManagementService
{
    public function __construct(private CollectorManagementDao $collectorManagementDao)
    {
    }

    private function requireInstalled(): void
    {
        if (!$this->collectorManagementDao->installed()) {
            throw new HttpException(503, '请先执行采集器数据库迁移');
        }
    }

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

    public function saveSettings(int $minutes, int $userId): array
    {
        $this->requireInstalled();
        $this->collectorManagementDao->saveSchedule($minutes, $userId);

        return $this->settings();
    }

    public function jobs(int $page, int $perPage = 20): array
    {
        $this->requireInstalled();

        return $this->collectorManagementDao->jobs($page, $perPage);
    }

    public function detail(string $id, int $page, int $perPage = 20): array
    {
        $this->requireInstalled();
        $job = $this->collectorManagementDao->findJob($id);
        if (!$job) {
            throw new HttpException(404, '采集任务不存在');
        }

        return [...$this->collectorManagementDao->summary($job), 'chunks' => $this->collectorManagementDao->chunks($id, $page, $perPage)];
    }

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
