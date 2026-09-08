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
    public function __construct(
        private AttachmentService $attachmentService,
        private BusinessOperationLogDao $businessOperationLogDao,
        private InvoiceOcrParserService $invoiceOcrParserService,
        private InvoiceOcrImageService $invoiceOcrImageService,
    ) {
    }

    /**
     * 识别截图文字。
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

    /** 调用已配置的原有 OCR 引擎，只传递经过附件权限校验的内部路径。 */
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

    /** 本地 Docker 没有常驻引擎时使用 Tesseract，进程参数不经过 shell。 */
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

    /** 文字按视觉行输出，商品卡片的行金额与下一行数量由 Parser 组合。 */
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
