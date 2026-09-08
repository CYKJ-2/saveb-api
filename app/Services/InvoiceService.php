<?php

namespace App\Services;

use App\Dao\BusinessOperationLogDao;
use App\Dao\InvoiceDao;
use App\Models\InvoiceOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Invoice 订单服务：处理业务规则、统计口径和事务。
 */
class InvoiceService
{
    public function __construct(
        private InvoiceDao $invoiceDao,
        private AttachmentService $attachmentService,
        private BusinessOperationLogDao $businessOperationLogDao,
        private InvoiceOcrParserService $invoiceOcrParserService,
        private InvoiceImageService $invoiceImageService,
    ) {
    }

    /**
     * 转换为接口返回结构。
     */
    public function present(InvoiceOrder $invoice): array
    {
        $data = $invoice->toArray();
        unset($data['raw']);
        $data['items'] = $invoice->items
            ->map(fn ($item) => collect($item->toArray())
                ->except(['metadata', 'raw'])
                ->all())
            ->all();

        return $data;
    }

    /**
     * 分页查询。
     */
    public function listing(array $filters): array
    {
        $paginator = $this->invoiceDao->listing($filters);

        return [
            'list' => collect($paginator->items())
                ->map(fn ($invoice) => $this->present($invoice))
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * 按标识查询记录。
     */
    public function find(int $id): array
    {
        $invoice = $this->invoiceDao->find($id);
        $data = $this->present($invoice);
        $images = $this->invoiceImageService->forInvoice($invoice);
        $data['invoice_screenshot_image'] = $images['screenshot'];
        foreach ($data['items'] as $index => $item) {
            $data['items'][$index]['image'] = $images['items'][$item['id']];
        }

        return $data;
    }

    /**
     * 计算下一个订单号。
     */
    public function nextNumber(): string
    {
        return $this->invoiceDao->nextNumber();
    }

    /** 表单沿用已有员工编码和当日生效汇率；兑美元汇率采用乘法。 */
    public function formOptions(): array
    {
        return [
            'staffCodes' => $this->invoiceDao->staffCodes(),
            'ratesToUsd' => $this->invoiceDao->exchangeRates(now('Asia/Shanghai')->toDateString()) + ['USD' => 1],
        ];
    }

    /** 返回粘贴文字中的业务字段建议，不生成内部订单号或添加日期。 */
    public function parseText(string $text): array
    {
        return $this->invoiceOcrParserService->parse($text) + ['text' => $text];
    }

    /**
     * 分页读取操作日志。
     */
    public function logs(int $page, int $perPage = 20): LengthAwarePaginator
    {
        return $this->businessOperationLogDao->list('invoice', $page, $perPage);
    }

    /**
     * 读取全部操作日志。
     */
    public function exportLogs(): iterable
    {
        foreach ($this->businessOperationLogDao->invoiceExportLogs() as $log) {
            $legacy = $log->after['legacyInvoiceOperationLog'] ?? [];
            yield [
                'operationTime' => $legacy['operationTime'] ?? $log->created_at?->toIso8601String(),
                'operator' => $legacy['operator'] ?? $log->operator_name ?? $log->actor_user_id,
                'action' => $legacy['action'] ?? $log->action,
                'orderNumber' => $legacy['orderNumber'] ?? $log->after['order_number'] ?? $log->before['order_number'] ?? $log->entity_id,
                'customer' => $legacy['customerFullName'] ?? $log->after['customer_full_name'] ?? $log->before['customer_full_name'] ?? '',
                'changedFields' => implode(' | ', $legacy['changedFields'] ?? []),
                'details' => $legacy['details'] ?? '',
                'recordId' => $legacy['recordId'] ?? $log->entity_id,
            ];
        }
    }

    public function allLogs(): iterable
    {
        return $this->businessOperationLogDao->all('invoice');
    }

    /**
     * 保存记录。
     */
    public function save(
        ?int $id,
        array $data,
        int $actor,
    ): array {
        // 原版允许选择状态，但只有已付款 Invoice 可以入库。旧客户端省略时兼容 Paid。
        abort_if(($data['invoice_status'] ?? 'Paid') !== 'Paid', 422, '该Invoice不是已付款状态');

        return DB::transaction(function () use ($id, $data, $actor) {
            $existingInvoice = $id ? $this->invoiceDao->find($id, true) : null;
            if ($existingInvoice) {
                abort_if((int) $existingInvoice->version !== $data['version'], 409, 'Invoice 已更新，请刷新');
            }
            $this->attachmentService->lockBindings(array_merge([$data['invoice_screenshot_attachment_id']], array_column($data['items'], 'image_attachment_id')));
            $screenshot = $this->attachmentService->validateBinding($data['invoice_screenshot_attachment_id'], $actor, $id, 'invoice_screenshot');
            $allocations = $data['allocations'];
            $sum = array_sum(array_column($allocations, 'percent'));
            abort_if(abs($sum - 100) > 0.001, 422, '客服分摊合计必须为 100%');
            $codes = array_map(fn ($allocation) => strtoupper(trim($allocation['staff_code'])), $allocations);
            abort_if(count($codes) !== count(array_unique($codes)), 422, '客服不可重复');
            $items = $data['items'];
            $images = [];
            foreach ($items as $item) {
                if (!empty($item['image_attachment_id'])) {
                    $images[] = $this->attachmentService->validateBinding($item['image_attachment_id'], $actor, $id, 'invoice_item_image');
                }
            }
            if (!$existingInvoice) {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['invoice-order-number']);
            }
            $payload = collect($data)
                ->except(['items', 'allocations', 'version'])
                ->all();
            $payload['order_number'] = $existingInvoice?->order_number ?? $this->invoiceDao->nextNumber();
            $payload['version'] = (int) $existingInvoice?->version + 1;
            $payload['created_by'] = $existingInvoice?->created_by ?? $actor;
            $payload['invoice_date'] = $existingInvoice?->invoice_date ?? $data['invoice_date'];
            $payload['invoice_status'] = 'Paid';
            $allocations = array_map(
                fn ($allocation) => [
                    'staff_code' => strtoupper(trim($allocation['staff_code'])),
                    'share_ratio' => $allocation['percent'] / 100,
                    'commission_percent' => $allocation['commission_percent'] ?? 0,
                ],
                $allocations,
            );
            $before = $existingInvoice ? $this->present($existingInvoice) : null;
            $invoice = $this->invoiceDao->save($existingInvoice, $payload, $items, $allocations);
            $this->attachmentService->bind($screenshot, 'invoice_screenshot', $invoice->id);
            foreach ($images as $image) {
                $this->attachmentService->bind($image, 'invoice_item_image', $invoice->id);
            }
            $this->businessOperationLogDao->record('invoice', (string) $invoice->id, $id ? 'update' : 'create', $actor, $before, $this->present($invoice));

            return $this->present($invoice);
        });
    }

    /**
     * 移除记录。
     */
    public function remove(
        int $id,
        int $version,
        int $actor,
    ): void {
        DB::transaction(function () use ($id, $version, $actor) {
            $invoice = $this->invoiceDao->find($id, true);
            abort_if((int) $invoice->version !== $version, 409, 'Invoice 已更新');
            $this->businessOperationLogDao->record('invoice', (string) $id, 'delete', $actor, $this->present($invoice), null);
            $this->invoiceDao->remove($invoice);
        });
    }
}
