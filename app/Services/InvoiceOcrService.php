<?php

namespace App\Services;

use App\Dao\BusinessOperationLogDao;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Invoice 截图识别服务：处理业务规则、统计口径和事务。
 */
class InvoiceOcrService
{
    /**
     * 注入 Invoice 截图识别处理所需的依赖。
     *
     * @param  AttachmentService  $attachmentService  附件业务服务
     * @param  BusinessOperationLogDao  $businessOperationLogDao  业务操作日志数据访问对象
     * @param  InvoiceOcrParserService  $invoiceOcrParserService  Invoice 文本解析业务服务
     * @param  InvoiceOcrImageService  $invoiceOcrImageService  OCR 图片预处理业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(
        private AttachmentService $attachmentService,
        private BusinessOperationLogDao $businessOperationLogDao,
        private InvoiceOcrParserService $invoiceOcrParserService,
        private InvoiceOcrImageService $invoiceOcrImageService,
    ) {
    }

    /**
     * 识别截图文字。
     *
     * @param  int  $id  Invoice 截图识别记录主键 ID
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @param  bool  $readBound  是否允许读取已绑定业务订单的附件
     * @return array OCR 引擎结果与可填入 Invoice 表单的字段建议
     * @see AttachmentService::file()
     * @see InvoiceOcrParserService::parse()
     * @see BusinessOperationLogDao::record()
     */
    public function recognize(
        int $id,
        int $actor,
        bool $readBound,
    ): array {
        [$attachment, $path] = $this->attachmentService->file($id, $actor, $readBound);
        $url = config('business.ocr_url');
        $relative = preg_replace('#^/data/attachments/#', '', $attachment->file_path);
        $result = $url ? $this->recognizeRemotely($url, $relative) : $this->recognizeLocally($path);
        $blocks = $result['blocks'];
        $text = implode("\n", array_column($blocks, 'text'));
        $parsed = $this->invoiceOcrParserService->parse($text);
        $this->businessOperationLogDao->record('invoice', (string) $id, 'ocr', $actor, null, [
            'blocks' => count($blocks),
            'engine' => $result['engine'],
        ]);

        return $parsed + [
            'text' => $text,
            // 保留旧调用方使用的建议键；新的表单直接使用 fields 的业务字段。
            'suggestions' => array_filter([
                'email' => $parsed['fields']['customer_email'] ?? null,
                'date' => $parsed['fields']['order_date'] ?? null,
                'amountUsd' => $parsed['fields']['amount_usd'] ?? null,
            ], fn ($value) => $value !== null),
            'blocks' => $blocks,
            'engine' => $result['engine'],
        ];
    }

    /**
     * 调用已配置的原有 OCR 引擎，只传递经过附件权限校验的内部路径。
     *
     * @param  string  $url  目标服务地址
     * @param  string  $relative  经过附件校验的存储相对路径
     * @return array Invoice 截图识别结果数组；返回字段：blocks、engine
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     */
    private function recognizeRemotely(string $url, string $relative): array
    {
        try {
            $result = Http::connectTimeout(5)
                ->timeout(90)
                ->post(rtrim($url, '/') . '/v1/ocr', [
                    'jobId' => (string) Str::uuid(),
                    'objectRef' => $relative,
                    'zones' => ['full'],
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            abort(503, 'OCR 服务暂时不可用，请稍后重试');
        }
        abort_unless($result->successful() && is_array($result->json('blocks')), 503, 'OCR 未完成，请检查图片后重试');

        return [
            'blocks' => $result->json('blocks'),
            'engine' => $result->json('engine') ?? 'remote',
        ];
    }

    /**
     * 本地 Docker 没有常驻引擎时使用 Tesseract，进程参数不经过 shell。
     *
     * @param  string  $path  本地文件绝对路径
     * @return array 本地 OCR 文本与对应解析建议
     * @see InvoiceOcrImageService::prepare()
     */
    private function recognizeLocally(string $path): array
    {
        $preparedPath = $this->invoiceOcrImageService->prepare($path);
        try {
            return $this->runLocalEngine($preparedPath);
        } finally {
            if ($preparedPath !== $path && is_file($preparedPath)) {
                unlink($preparedPath);
            }
        }
    }

    /**
     * 文字按视觉行输出，商品卡片的行金额与下一行数量由 Parser 组合。
     *
     * @param  string  $path  本地文件绝对路径
     * @return array Invoice 截图识别结果数组；返回字段：engine、blocks
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     */
    private function runLocalEngine(string $path): array
    {
        $process = new Process([
            config('business.ocr_binary'), $path, 'stdout',
            '-l', config('business.ocr_languages'), '--psm', '6',
        ], null, ['OMP_THREAD_LIMIT' => '2']);
        $process->setTimeout(90);
        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            abort(503, 'OCR 识别超时，请裁剪图片后重试');
        }
        abort_unless($process->isSuccessful(), 503, 'OCR 服务暂时不可用，请检查本地识别引擎');
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $process->getOutput()))));

        return [
            'engine' => 'tesseract',
            'blocks' => array_map(fn ($line) => ['text' => $line], $lines),
        ];
    }
}
