<?php

namespace App\Services;

use App\Dao\OrderManagementDao;
use App\Models\InvoiceOrder;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 订单管理服务：处理业务规则、统计口径和事务。
 */
class OrderManagementService
{
    public const CATEGORIES = [
        'official' => '官方站点',
        'top_influencer' => '头部达人',
        'mid_influencer' => '中腰部达人',
        'offline' => '线下订单',
        'invoice' => 'Invoice 订单',
        'unmatched' => '未匹配',
    ];

    /**
     * 注入 订单管理处理所需的依赖。
     *
     * @param  OrderManagementDao  $orderManagementDao  订单管理数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private OrderManagementDao $orderManagementDao)
    {
    }

    /**
     * 归一化不同来源的订单状态别名。
     *
     * @param  string|null  $value  待归一化的原始值
     * @return string 标准化后的业务状态
     */
    public static function status(?string $value): string
    {
        $value = strtolower(trim($value ?? ''));

        return match ($value) {
            'complete', 'completed', 'paid', 'success' => 'completed',
            'pending', 'processing', 'authorized', 'awaiting' => 'pending',
            default => $value,
        };
    }

    /**
     * 合并重复客服并归一化分摊比例；未提供比例时平均分配。
     *
     * @param  array  $raw  来源系统保留的原始业务快照；本方法读取 staffAllocations
     * @param  string  $staff  订单主客服编码
     * @return array 合并并归一化后的客服分摊明细
     */
    public static function allocations(array $raw, string $staff): array
    {
        $source = $raw['staffAllocations'] ?? [];
        if (!$source) {
            $source = array_map(fn ($staffCode) => ['staffCode' => trim($staffCode)], preg_split('/[,，\/]+/', $staff));
        }
        $values = [];
        foreach ($source as $item) {
            $code = strtoupper(trim($item['staffCode'] ?? $item['staff'] ?? ''));
            if ($code === '') {
                continue;
            }
            $values[$code] = ($values[$code] ?? 0) + max(0, (float) ($item['shareRatio'] ?? $item['share_ratio'] ?? ($item['percent'] ?? 0) / 100));
        }
        $total = array_sum($values);
        $count = count($values);

        return array_map(
            fn ($code, $ratio) => [
                'staffCode' => $code,
                'shareRatio' => $total > 0 ? $ratio / $total : 1 / $count,
            ],
            array_keys($values),
            array_values($values),
        );
    }

    /**
     * 合并来源订单与 Invoice，统一字段后筛选，供列表、统计和导出共用。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 _orderId
     * @param  bool  $allowTesting  是否允许纳入测试订单；默认 false
     * @return array 合并去重并筛选后的统一订单列表，尚未分页
     * @see OrderManagementDao::overrides()
     * @see OrderManagementDao::rates()
     * @see OrderManagementDao::invoiceKeys()
     * @see OrderManagementDao::invoices()
     * @see OrderManagementDao::orders()
     */
    public function rows(array $filters, bool $allowTesting = false): array
    {
        $overrides = $this->orderManagementDao->overrides();
        $rates = $this->orderManagementDao->rates();
        $rows = [];
        $invoiceKeys = $this->orderManagementDao->invoiceKeys();
        foreach (isset($filters['_orderId']) ? [] : $this->orderManagementDao->invoices($filters) as $invoice) {
            $invoiceKeys[$invoice->order_number] = true;
            $rows[] = $this->normalizeInvoice($invoice);
        }
        foreach ($this->orderManagementDao->orders($filters) as $order) {
            if ($order->classification === 'invoice' && (isset($invoiceKeys[$order->client_order_id]) || isset($invoiceKeys[$order->order_id]))) {
                continue;
            }
            $rows[] = $this->normalizeOrder($order, $overrides, $rates);
        }
        $rows = array_filter($rows, fn (array $row) => $this->matchesFilters($row, $filters, $allowTesting));
        // 日期只解析一次；比较函数会调用约 n log n 次，不能反复解析同一日期。
        $timestamps = array_map(fn ($row) => strtotime($row['createTime']), $rows);
        $identifiers = array_map(fn ($row) => (string) $row['id'], $rows);
        if ($rows !== []) {
            $positions = range(0, count($rows) - 1);
            array_multisort($timestamps, SORT_DESC, SORT_NUMERIC, $identifiers, SORT_DESC, SORT_STRING, $positions, SORT_ASC, SORT_NUMERIC, $rows);
        }

        return array_values($rows);
    }

    /**
     * 对统一筛选结果分页，保持列表与统计、导出的数据口径一致。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件
     * @param  bool  $testing  是否允许查询测试订单
     * @return array 当前页记录及分页信息；汇总字段按业务方法计算
     */
    public function search(array $filters, bool $testing): array
    {
        $rows = $this->rows($filters, $testing);

        return \App\Common\PageResult::fromRows($rows, $filters);
    }

    /**
     * 获取编辑面板的客服选项。
     *
     * @return array 订单管理结果数组；返回字段：staff
     * @see OrderManagementDao::staffCodes()
     */
    public function editorOptions(): array
    {
        return ['staff' => $this->orderManagementDao->staffCodes()];
    }

    /**
     * 校验版本并调整订单状态和客服分摊。
     *
     * @param  int  $id  订单管理记录主键 ID
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 version、targetStatus、staffAllocations、primaryStaffCode
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @param  bool  $allowTesting  是否允许纳入测试订单；默认 false
     * @return array 订单管理结果数组，包含 id 等字段
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     * @see OrderManagementDao::lock()
     * @see OrderManagementDao::save()
     */
    public function adjust(
        int $id,
        array $data,
        int $actor,
        bool $allowTesting = false,
    ): array {
        return DB::connection('pgsql')
            ->transaction(function () use ($id, $data, $actor, $allowTesting) {
                $order = $this->orderManagementDao->lock($id);
                abort_if(preg_match('/\btest/i', $order->customer_name ?? '') && !$allowTesting, 403);
                abort_if((int) $order->version !== (int) $data['version'], 409, '订单已被修改，请刷新后重试');
                $current = $this->rows([
                    '_orderId' => $id,
                    'scope' => preg_match('/\btest/i', $order->customer_name ?? '') ? 'testing' : 'normal',
                ], true);
                $old = collect($current)->first(fn ($orderRow) => (string) $orderRow['id'] === (string) $id);
                // 编辑状态必须与列表一致，包括已导入的状态覆盖。
                $status = $old['paymentStatus'] ?? self::status($order->order_status);
                if (!in_array($status, ['pending', 'completed', 'failed'], true)) {
                    throw ValidationException::withMessages(['order' => '此状态不支持编辑客服']);
                }
                if (isset($data['targetStatus']) && $status !== 'pending') {
                    throw ValidationException::withMessages(['targetStatus' => '仅待处理订单允许确认完成']);
                }
                $sum = array_sum(array_column($data['staffAllocations'], 'percent'));
                if (abs($sum - 100) > 0.001) {
                    throw ValidationException::withMessages(['staffAllocations' => '分摊比例合计必须为 100%']);
                }
                $codes = array_map(fn ($allocation) => strtoupper(trim($allocation['staffCode'])), $data['staffAllocations']);
                if (count(array_unique($codes)) !== count($codes)) {
                    throw ValidationException::withMessages(['staffAllocations' => '客服不可重复']);
                }
                $primary = strtoupper(trim($data['primaryStaffCode']));
                if (!in_array($primary, $codes, true)) {
                    throw ValidationException::withMessages(['primaryStaffCode' => '主客服必须包含在分摊列表']);
                }
                // 主客服可通过编辑面板更换，但必须保留在销售分摊中；更换前后均写入历史。
                $raw = $order->raw ?? [];
                $raw['dashboardEditHistory'][] = [
                    'at' => now()->toIso8601String(),
                    'actor' => $actor,
                    'before' => [
                        'status' => $old['paymentStatus'] ?? $status,
                        'staff' => $old['staff'] ?? $order->staff_code,
                        'primaryStaffCode' => $old['primaryStaffCode'] ?? $order->staff_code,
                        'allocations' => $old['staffAllocations'] ?? [],
                    ],
                    'after' => $data,
                ];
                $raw['staffAllocations'] = array_map(
                    fn ($allocation) => [
                        'staffCode' => strtoupper(trim($allocation['staffCode'])),
                        'percent' => (float) $allocation['percent'],
                    ],
                    $data['staffAllocations'],
                );
                $raw['primaryStaffCode'] = $primary;
                $raw['dashboardEditedAt'] = now()->toIso8601String();
                $raw['dashboardEditedBy'] = $actor;
                $this->orderManagementDao->save(
                    $order,
                    [
                        'staff_code' => $primary,
                        'order_status' => $data['targetStatus'] ?? $old['paymentStatus'] ?? $status,
                        'raw' => $raw,
                        'version' => (int) $order->version + 1,
                    ],
                );

                return [
                    'id' => $order->id,
                    'version' => $order->version,
                ];
            });
    }

    /**
     * 将 Invoice 商品、客服分摊和金额转换为统一订单字段。
     *
     * @param  InvoiceOrder  $invoice  Invoice 订单模型
     * @return array 订单管理结果数组；返回字段：id、kind、orderId、paypalOrderId、customerFullName、clientSite、classification、topInfluencer、recipientPaypal、paymentStatus、amount、amountUsd、currency、items、productName、products、createTime、date、staff、staffAllocations、version
     */
    private function normalizeInvoice(InvoiceOrder $invoice): array
    {
        $date = $invoice->order_date ?: $invoice->invoice_date;

        return [
            'id' => 'invoice:' . $invoice->id,
            'kind' => 'invoice',
            'orderId' => $invoice->order_number,
            'paypalOrderId' => '',
            'customerFullName' => $invoice->customer_full_name,
            'clientSite' => 'Invoice Orders',
            'classification' => 'invoice',
            'topInfluencer' => '',
            'recipientPaypal' => $invoice->recipient_paypal,
            'paymentStatus' => self::status($invoice->invoice_status),
            'amount' => (float) $invoice->amount_usd,
            'amountUsd' => (float) $invoice->amount_usd,
            'currency' => 'USD',
            'items' => (int) $invoice->items->sum('quantity'),
            'productName' => $invoice->items
                ->pluck('product_name')
                ->implode(' / '),
            'products' => $invoice->items
                ->map(fn ($item) => [
                    'name' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                ])
                ->all(),
            'createTime' => $date . 'T00:00:00+08:00',
            'date' => $date,
            'staff' => $invoice->allocations
                ->pluck('staff_code')
                ->implode(', '),
            'staffAllocations' => self::allocations(
                [
                    'staffAllocations' => $invoice->allocations
                        ->map(fn ($allocation) => [
                            'staffCode' => $allocation->staff_code,
                            'shareRatio' => (float) $allocation->share_ratio,
                        ])
                        ->all(),
                ],
                '',
            ),
            'version' => (int) $invoice->version,
        ];
    }

    /**
     * 从采集快照提取各商品的名称、链接和数量，供列表逐件展示。
     *
     * @param  array  $raw  来源订单快照，读取 products 中的 name、url、quantity
     * @return array<int, array{name: string, url: string, quantity: int}> 商品明细；缺少明细时返回空数组，沿用订单汇总名称
     */
    private function orderProducts(array $raw): array
    {
        $products = [];
        $source = is_array($raw['products'] ?? null) ? $raw['products'] : [];
        foreach ($source as $product) {
            if (!is_array($product) || !is_string($product['name'] ?? null)) {
                continue;
            }
            $name = trim($product['name']);
            if ($name === '') {
                continue;
            }
            $products[] = [
                'name' => $name,
                'url' => is_string($product['url'] ?? null) ? trim($product['url']) : '',
                'quantity' => max(1, (int) ($product['quantity'] ?? 1)),
            ];
        }

        return $products;
    }

    /**
     * 合并历史人工调整并按订单日期补算美元金额；本地调整优先。
     *
     * @param  Order  $order  来源订单模型
     * @param  array  $overrides  原平台保存的订单人工调整映射
     * @param  array  $rates  币种换算汇率数据
     * @return array 叠加人工调整与美元换算后的统一普通订单字段
     */
    private function normalizeOrder(
        Order $order,
        array $overrides,
        array $rates,
    ): array {
        $row = Order::present($order);
        $raw = $order->raw ?? [];
        $products = $this->orderProducts($raw);
        if ($products !== []) {
            $row['products'] = $products;
        }
        $row['sourceIdentity'] = $order->client_order_id
            ? 'client:' . $order->client_order_id
            : 'order:' . $order->order_id;
        // 原页面在订单ID下展示来源 orderId；导入的 paypal_order_id 有时存的是 ApplePay 等支付方式。
        $row['paypalOrderId'] = trim((string) ($raw['orderId'] ?? '')) ?: ($order->paypal_order_id ?? '');
        $override = $overrides['uuid:' . $order->entity_uuid] ?? $overrides['client:' . $order->client_order_id] ?? $overrides['order:' . $order->order_id] ?? $overrides['paypal:' . $order->paypal_order_id] ?? null;
        $row['kind'] = 'order';
        $row['classification'] = $order->classification === 'payment_link' ? 'offline' : ($order->classification ?: 'unmatched');
        $row['paymentStatus'] = self::status($order->order_status);
        // 本地带版本号的调整优先于历史导入的覆盖记录。
        if ($override && empty($raw['dashboardEditedAt'])) {
            $row['paymentStatus'] = self::status($override->status_override);
            $row['staff'] = $override->primary_staff_code ?: $row['staff'];
            if ($override->allocations->isNotEmpty()) {
                $raw['staffAllocations'] = $override->allocations
                    ->map(fn ($allocation) => [
                        'staffCode' => $allocation->staff_code,
                        'shareRatio' => (float) $allocation->share_ratio,
                    ])
                    ->all();
            }
        }
        $row['staffAllocations'] = self::allocations($raw, $row['staff'] ?? '');
        $row['primaryStaffCode'] = strtoupper(trim($raw['primaryStaffCode'] ?? ($override?->primary_staff_code ?: preg_split('/[,，\/]+/', $row['staff'] ?? '')[0] ?? '')));
        // 原平台归类下方展示所有参与客服；主客服仍由 primaryStaffCode 单独标识。
        $row['staff'] = implode(', ', array_column($row['staffAllocations'], 'staffCode'));
        $row['date'] = CarbonImmutable::parse($row['createTime'])
            ->setTimezone('Asia/Shanghai')
            ->toDateString();
        if ($row['amountUsd'] === null) {
            $rate = $row['currency'] === 'USD' ? 1 : null;
            foreach ($rates[$row['currency']] ?? [] as $record) {
                if ($record->effective_date <= $row['date']) {
                    $rate = (float) $record->rate_to_usd;
                }
            }
            $row['amountUsd'] = $rate === null ? null : round($row['amount'] * $rate, 2);
        }

        return $row;
    }

    /**
     * 对列表、统计和导出使用相同的测试订单隔离及筛选规则。
     *
     * @param  array  $row  订单管理单条记录
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 scope、orderStatus、classification、influencerExact、customerService、staffExact
     * @param  bool  $allowTesting  是否允许纳入测试订单
     * @return bool 订单满足全部筛选条件及测试订单访问限制时为 true
     */
    private function matchesFilters(
        array $row,
        array $filters,
        bool $allowTesting,
    ): bool {
        $testing = preg_match('/\btest/i', $row['customerFullName'] ?? '') === 1;
        if ($testing && !$allowTesting) {
            return false;
        }
        if (($filters['scope'] ?? 'normal') === 'testing' ? !$testing : $testing) {
            return false;
        }
        if (!empty($filters['orderStatus']) && $row['paymentStatus'] !== self::status($filters['orderStatus'])) {
            return false;
        }
        if (!empty($filters['classification']) && $row['classification'] !== $filters['classification']) {
            return false;
        }
        foreach ([
            'orderId' => 'orderId',
            'paypalOrderId' => 'paypalOrderId',
            'customerName' => 'customerFullName',
            'paypalAccount' => 'recipientPaypal',
            'website' => 'clientSite',
            'influencer' => 'topInfluencer',
        ] as $filter => $field) {
            if ($filter === 'influencer' && !empty($filters['influencerExact']) && !empty($filters[$filter]) && strcasecmp($row[$field] ?? '', $filters[$filter]) !== 0) {
                return false;
            }
            if (!empty($filters[$filter]) && mb_stripos($row[$field] ?? '', $filters[$filter]) === false) {
                return false;
            }
        }
        if (!empty($filters['customerService']) && mb_stripos(implode(' ', array_column($row['staffAllocations'], 'staffCode')), $filters['customerService']) === false) {
            return false;
        }
        if (!empty($filters['staffExact']) && !empty($filters['customerService']) && !in_array(strtoupper($filters['customerService']), array_column($row['staffAllocations'], 'staffCode'), true)) {
            return false;
        }

        return true;
    }
}
