<?php

namespace App\Services;

use App\Dao\BusinessOperationLogDao;
use App\Dao\LogisticsDao;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** API 仅鉴权、提交及核对同库任务；第三方物流调用全部由 Collector 执行。 */
class LogisticsService
{
    public function __construct(private LogisticsDao $logisticsDao, private BusinessOperationLogDao $businessOperationLogDao)
    {
    }

    public function status(?string $jobId): array
    {
        return $this->request('get', '/api/logistics/status', $jobId ? ['jobId' => $jobId] : []);
    }

    public function refresh(array $data, int $actor): array
    {
        $result = $this->request('post', '/api/logistics/refresh', [
            'provider' => $data['provider'] ?? 'auto',
            'taskId' => $data['taskId'] ?? null,
        ], ['Idempotency-Key' => $data['requestId'], 'X-Collector-Actor' => 'saveb-api:' . $actor]);
        $job = $this->logisticsDao->find($result['jobId'] ?? '');
        if (!$job || $job->actor !== 'saveb-api:' . $actor) {
            throw new HttpException(503, '物流任务未写入当前数据库，请检查 Collector 数据库配置');
        }
        $this->businessOperationLogDao->record('procurement', $job->id, 'refresh-logistics', $actor, null, ['jobId' => $job->id, 'taskId' => $data['taskId'] ?? null]);

        return $result;
    }

    private function request(string $method, string $path, array $data, array $headers = []): array
    {
        if (!config('collector.token')) {
            throw new HttpException(503, '采集服务令牌尚未配置');
        }
        try {
            $response = Http::withToken(config('collector.token'))->withHeaders($headers)
                ->connectTimeout(3)->timeout(15)->withoutRedirecting()
                ->{$method}(rtrim(config('collector.url'), '/') . $path, $data);
        } catch (ConnectionException $exception) {
            throw new HttpException(503, '物流采集服务连接失败，请稍后重试');
        }
        if (!$response->successful()) {
            $message = match ($response->json('error')) {
                'LOGISTICS_NOT_CONFIGURED' => '请先在 Collector 配置 AfterShip 或快递100 API Key',
                'TRACKING_TASK_NOT_FOUND' => '采购记录不存在或尚未填写物流单号',
                'API_PUBLICATION_DISABLED' => 'Collector 尚未开启 API 数据库写入',
                default => '物流任务提交失败，请检查 Collector 服务',
            };
            throw new HttpException($response->status() === 422 ? 422 : 503, $message);
        }

        return $response->json();
    }
}
