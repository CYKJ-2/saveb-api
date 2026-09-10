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
    /**
     * 注入 物流采集处理所需的依赖。
     *
     * @param  LogisticsDao  $logisticsDao  物流采集数据访问对象
     * @param  BusinessOperationLogDao  $businessOperationLogDao  业务操作日志数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private LogisticsDao $logisticsDao, private BusinessOperationLogDao $businessOperationLogDao)
    {
    }

    /**
     * 读取物流服务状态或指定物流任务的执行进度。
     *
     * @param  string|null  $jobId  采集任务编号
     * @return array Collector 返回的物流服务或指定任务状态
     */
    public function status(?string $jobId): array
    {
        return $this->request('get', '/api/logistics/status', $jobId ? ['jobId' => $jobId] : []);
    }

    /**
     * 提交物流刷新任务并记录操作人。
     *
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 provider、taskId、requestId
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return array Collector 已受理并经本地数据库核验的物流任务结果
     * @see LogisticsDao::find()
     * @see BusinessOperationLogDao::record()
     */
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

    /**
     * 调用 Collector 物流接口，将连接或业务失败转换为 HTTP 异常。
     *
     * @param  string  $method  HTTP 请求方法，如 get 或 post
     * @param  string  $path  Collector 物流接口路径
     * @param  array  $data  经过 Controller 校验的业务字段
     * @param  array  $headers  需要附带的 HTTP 请求头
     * @return array 成功响应的 JSON 数据；失败时抛 HTTP 异常
     */
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
