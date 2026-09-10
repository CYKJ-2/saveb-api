<?php

namespace App\Services;

use App\Dao\BusinessOperationLogDao;
use App\Dao\InvoiceDao;
use App\Models\BusinessOperationLog;
use App\Models\InvoiceOrder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Invoice 订单服务：处理业务规则、统计口径和事务。
 */
class InvoiceService
{
    /**
     * 注入 Invoice 订单处理所需的依赖。
     *
     * @param  InvoiceDao  $invoiceDao  Invoice 订单数据访问对象
     * @param  AttachmentService  $attachmentService  附件业务服务
     * @param  BusinessOperationLogDao  $businessOperationLogDao  业务操作日志数据访问对象
     * @param  InvoiceOcrParserService  $invoiceOcrParserService  Invoice 文本解析业务服务
     * @param  InvoiceImageService  $invoiceImageService  Invoice 图片业务服务
     * @return void 无返回值；完成依赖初始化
     */
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
     *
     * @param  InvoiceOrder  $invoice  Invoice 订单模型
     * @return array Invoice 订单字段及商品、客服分摊；列表序列化不携带图片内容
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
     * 按筛选条件分页查询 Invoice 订单。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件
     * @return array Invoice 订单结果数组；返回字段：list、total、page、per_page、last_page
     * @see InvoiceDao::listing()
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
     * 按主键读取 Invoice 订单详情。
     *
     * @param  int  $id  Invoice 订单记录主键 ID
     * @return array Invoice 订单详情，附带截图和各商品图片的内联预览信息
     * @see InvoiceDao::find()
     * @see InvoiceImageService::forInvoice()
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
     *
     * @return string 下一个可用的数字 Invoice 订单号，最小为 10000
     * @see InvoiceDao::nextNumber()
     */
    public function nextNumber(): string
    {
        return $this->invoiceDao->nextNumber();
    }

    /**
     * 表单沿用已有员工编码和当日生效汇率；兑美元汇率采用乘法。
     *
     * @return array Invoice 订单结果数组；返回字段：staffCodes、ratesToUsd
     * @see InvoiceDao::staffCodes()
     * @see InvoiceDao::exchangeRates()
     */
    public function formOptions(): array
    {
        return [
            'staffCodes' => $this->invoiceDao->staffCodes(),
            'ratesToUsd' => $this->invoiceDao->exchangeRates(now('Asia/Shanghai')->toDateString()) + ['USD' => 1],
        ];
    }

    /**
     * 返回粘贴文字中的业务字段建议，不生成内部订单号或添加日期。
     *
     * @param  string  $text  截图识别或粘贴得到的原始文本
     * @return array 解析后的表单字段建议、原币金额、付款状态和识别提示
     * @see InvoiceOcrParserService::parse()
     */
    public function parseText(string $text): array
    {
        return $this->invoiceOcrParserService->parse($text) + ['text' => $text];
    }

    /**
     * 分页读取操作日志。
     *
     * @param  int  $page  页码，从 1 开始
     * @param  int  $perPage  每页条数；默认 20
     * @param  string  $locale  展示语言，zh-CN 或 en-US
     * @return LengthAwarePaginator 当前页日志及分页信息，展示字段与导出一致，保留原始日志字段
     * @see BusinessOperationLogDao::invoiceLogs()
     */
    public function logs(int $page, int $perPage = 20, string $locale = 'zh-CN'): LengthAwarePaginator
    {
        return $this->businessOperationLogDao->invoiceLogs($page, $perPage)
            ->through(fn ($log) => array_merge($log->toArray(), $this->presentLog($log, $locale)));
    }

    /**
     * 读取全部操作日志。
     *
     * @param  string  $locale  导出语言，zh-CN 或 en-US
     * @return iterable 按需迭代的日志展示字段，与分页列表使用相同转换规则
     * @see BusinessOperationLogDao::invoiceExportLogs()
     */
    public function exportLogs(string $locale = 'zh-CN'): iterable
    {
        foreach ($this->businessOperationLogDao->invoiceExportLogs() as $log) {
            yield $this->presentLog($log, $locale);
        }
    }

    /**
     * 统一列表与导出的八个展示字段，兼容原平台日志及已删除订单的快照。
     *
     * @param  BusinessOperationLog  $log  已关联操作人名称的 Invoice 日志
     * @param  string  $locale  展示语言，zh-CN 或 en-US
     * @return array 日志 ID、北京时间、操作人、动作及订单变更信息；缺少信息时返回空字符串
     */
    private function presentLog(BusinessOperationLog $log, string $locale): array
    {
        $legacy = $log->after['legacyInvoiceOperationLog'] ?? [];
        $operator = trim((string) ($legacy['operator'] ?? ''))
            ?: trim((string) $log->operator_display_name)
            ?: trim((string) $log->operator_name)
            ?: ($log->actor_user_id ? '#' . $log->actor_user_id : '');
        $action = (string) ($legacy['action'] ?? $log->action);
        $labels = $locale === 'en-US'
            ? ['create' => 'Create', 'update' => 'Edit', 'delete' => 'Delete', 'ocr' => 'Invoice recognition']
            : ['create' => '新增', 'update' => '编辑', 'delete' => '删除', 'ocr' => 'Invoice 截图识别'];
        $operationTime = $log->created_at;
        if (!empty($legacy['operationTime'])) {
            try {
                $operationTime = CarbonImmutable::parse($legacy['operationTime'], 'Asia/Shanghai');
            } catch (\Exception) {
                // 旧时间不可解析时使用日志入库时间，避免整页日志读取失败。
            }
        }

        return [
            'id' => $log->id,
            'operationTime' => $operationTime?->copy()->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?? '',
            'operator' => $operator,
            'actionLabel' => $labels[strtolower($action)] ?? $action,
            'orderNumber' => (string) ($legacy['orderNumber'] ?? $log->after['order_number'] ?? $log->before['order_number'] ?? ($log->action === 'ocr' ? '' : $log->entity_id)),
            'customer' => (string) ($legacy['customerFullName'] ?? $log->after['customer_full_name'] ?? $log->before['customer_full_name'] ?? ''),
            'changedFields' => implode(' | ', $legacy['changedFields'] ?? []),
            'details' => (string) ($legacy['details'] ?? ''),
            'recordId' => (string) ($legacy['recordId'] ?? $log->entity_id),
        ];
    }

    /**
     * 按时间读取全部 Invoice 操作日志，供导出使用。
     *
     * @return iterable 按需迭代的Invoice 订单记录，供逐条处理或导出
     * @see BusinessOperationLogDao::all()
     */
    public function allLogs(): iterable
    {
        return $this->businessOperationLogDao->all('invoice');
    }

    /**
     * 保存 Invoice 订单及其关联数据。
     *
     * @param  int|null  $id  Invoice 订单记录主键 ID
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 invoice_status、version、invoice_screenshot_attachment_id、items、allocations、invoice_date
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return array 保存后的 Invoice 订单及商品、客服分摊字段
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     * @see InvoiceDao::find()
     * @see AttachmentService::lockBindings()
     * @see AttachmentService::validateBinding()
     * @see InvoiceDao::nextNumber()
     * @see InvoiceDao::save()
     * @see AttachmentService::bind()
     * @see BusinessOperationLogDao::record()
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
     * 移除 Invoice 订单记录。
     *
     * @param  int  $id  Invoice 订单记录主键 ID
     * @param  int  $version  客户端读取的乐观锁版本，用于检测并发修改
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return void 无返回值；副作用见方法说明
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     * @see InvoiceDao::find()
     * @see BusinessOperationLogDao::record()
     * @see InvoiceDao::remove()
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
