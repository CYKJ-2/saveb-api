<?php

namespace App\Services;

use App\Dao\BusinessOperationLogDao;
use App\Dao\PaypalDao;
use App\Models\PaypalAccount;
use App\Models\PaypalBalanceEntry;
use Illuminate\Support\Facades\DB;

/**
 * PayPal 账户服务：处理业务规则、统计口径和事务。
 */
class PaypalService
{
    /**
     * 列表分页；汇总始终覆盖完整筛选范围，与当前页无关。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 keyword、sort、threshold
     * @return array 当前页记录及分页信息；汇总字段按业务方法计算
     */
    public function page(array $filters): array
    {
        $rows = $this->listing(trim($filters['keyword'] ?? ''));
        $sort = $filters['sort'] ?? 'latestIncomingAt';
        usort($rows, function ($first, $second) use ($sort) {
            $difference = $sort === 'latestIncomingAt'
                ? (strtotime($second[$sort] ?? '') ?: 0) <=> (strtotime($first[$sort] ?? '') ?: 0)
                : $second[$sort] <=> $first[$sort];

            return $difference ?: ($second['received'] <=> $first['received']) ?: ($first['id'] <=> $second['id']);
        });

        return \App\Common\PageResult::fromRows($rows, $filters) + [
            'summary' => [
                'accounts' => count($rows),
                'balance' => round(array_sum(array_column($rows, 'balance')), 2),
                'aboveThreshold' => count(array_filter($rows, fn ($row) => $row['balance'] >= ($filters['threshold'] ?? 5000))),
            ],
        ];
    }

    /**
     * 收款明细分页，下载仍使用完整的 orders 结果。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 email
     * @return array 当前页记录及分页信息；汇总字段按业务方法计算
     */
    public function orderPage(array $filters): array
    {
        return \App\Common\PageResult::fromRows($this->orders($filters['email']), $filters);
    }

    /**
     * 提款总额先统计再分页，导入累计记录保留原有口径。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件
     * @return array 当前页记录及分页信息；汇总字段按业务方法计算
     */
    public function withdrawalPage(array $filters): array
    {
        $result = $this->withdrawals($filters);

        return \App\Common\PageResult::fromRows($result['rows'], $filters) + [
            'count' => $result['count'],
            'amount' => $result['amount'],
        ];
    }

    /**
     * 注入 PayPal 账户处理所需的依赖。
     *
     * @param  PaypalDao  $paypalDao  PayPal 账户数据访问对象
     * @param  PaypalActivityService  $paypalActivityService  PayPal 收款业务服务
     * @param  BusinessOperationLogDao  $businessOperationLogDao  业务操作日志数据访问对象
     * @param  PaypalLegacyService  $paypalLegacyService  PayPal 历史资料业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(
        private PaypalDao $paypalDao,
        private PaypalActivityService $paypalActivityService,
        private BusinessOperationLogDao $businessOperationLogDao,
        private PaypalLegacyService $paypalLegacyService,
    ) {
    }

    /**
     * 计算账户收支。
     *
     * @return array 按账户邮箱分组的美元收款合计及订单明细
     * @see PaypalActivityService::groups()
     */
    public function activity(): array
    {
        return $this->paypalActivityService->groups();
    }

    /**
     * 查询账户列表，按当前余额从高到低排序。
     *
     * @param  string  $keyword  列表关键字；空字符串表示不按关键字过滤；默认 ''
     * @return array 按余额降序排列的账户记录，包含收款、提款及余额基线计算结果
     * @see PaypalDao::all()
     */
    public function listing(string $keyword = ''): array
    {
        $accounts = $this->paypalDao->all()->filter(fn ($account) => $keyword === '' || mb_stripos($account->email . ' ' . $account->account_name, $keyword) !== false);
        // 搜索一个账户时，仅计算该账户收款；全局日快照分界仍由 Dao 独立获取。
        $activity = $keyword === '' ? $this->activity() : $this->paypalActivityService->groups($accounts->pluck('email')->all());
        $rows = [];
        foreach ($accounts as $account) {
            $rows[] = $this->presentAccount($account, $activity);
        }
        usort($rows, fn ($firstRow, $secondRow) => $secondRow['balance'] <=> $firstRow['balance']);

        return $rows;
    }

    /**
     * 查询关联订单。
     *
     * @param  string  $email  PayPal 收款账户邮箱
     * @return array 指定收款邮箱的全部关联订单明细
     */
    public function orders(string $email): array
    {
        return $this->paypalActivityService->groups([$email])[strtolower($email)]['orders'] ?? [];
    }

    /**
     * 提款记录与筛选合计，不重复叠加已经迁入流水表的历史导入金额。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 keyword、startDate、endDate
     * @param  bool  $forChart  是否按图表口径过滤提款记录；默认 false
     * @return array PayPal 账户结果数组；返回字段：rows、count、amount
     * @see PaypalLegacyService::catalog()
     * @see PaypalDao::withdrawals()
     * @see PaypalDao::all()
     * @see PaypalLegacyService::entries()
     * @see PaypalLegacyService::account()
     */
    public function withdrawals(array $filters, bool $forChart = false): array
    {
        $catalog = $this->paypalLegacyService->catalog();
        $rows = $this->paypalDao->withdrawals($filters)->filter(fn ($withdrawal) => !$catalog || $withdrawal->id > $catalog['withdrawalWatermark'])->map(fn ($withdrawal) => [
            'id' => $withdrawal->id,
            'date' => substr((string) $withdrawal->withdrawn_at, 0, 10),
            'accountName' => $withdrawal->account->account_name,
            'email' => $withdrawal->account->email,
            'amount' => round((float) $withdrawal->amount, 2),
            'source' => $withdrawal->source,
            'imported' => false,
        ])->values()->all();
        if ($catalog) {
            foreach ($this->paypalDao->all() as $account) {
                $keyword = $filters['keyword'] ?? '';
                if ($keyword !== '' && mb_stripos($account->account_name . ' ' . $account->email, $keyword) === false) {
                    continue;
                }
                $accountInfo = ['accountName' => $account->account_name, 'email' => $account->email];
                foreach ($this->paypalLegacyService->entries($account->email, $forChart) as $entry) {
                    if ((!empty($filters['startDate']) && $entry['date'] < $filters['startDate'])
                        || (!empty($filters['endDate']) && $entry['date'] > $filters['endDate'])) {
                        continue;
                    }
                    $rows[] = array_merge($entry, $accountInfo);
                }
                $imported = (float) ($this->paypalLegacyService->account($account->email)['importedTotalWithdrew'] ?? 0);
                if (!$forChart && $imported > 0) {
                    $rows[] = array_merge($accountInfo, ['id' => 'imported:' . $account->id, 'date' => '',
                        'amount' => round($imported, 2), 'source' => '', 'imported' => true]);
                }
            }
        }
        usort($rows, fn ($left, $right) => strcmp($right['date'], $left['date']) ?: $right['amount'] <=> $left['amount']);

        return ['rows' => $rows, 'count' => count($rows), 'amount' => round(array_sum(array_column($rows, 'amount')), 2)];
    }

    /**
     * 按实际提款业务日期聚合日/月趋势，与列表筛选相互独立。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 mode
     * @return array 按 period 排序的日或月提款金额列表，amount 保留两位小数
     */
    public function withdrawalStatistics(array $filters): array
    {
        $amounts = [];
        foreach ($this->withdrawals($filters, true)['rows'] as $withdrawal) {
            if (!$withdrawal['date']) {
                continue;
            }
            $period = substr($withdrawal['date'], 0, ($filters['mode'] ?? 'daily') === 'monthly' ? 7 : 10);
            $amounts[$period] = ($amounts[$period] ?? 0) + $withdrawal['amount'];
        }
        ksort($amounts);
        $rows = [];
        foreach ($amounts as $period => $amount) {
            $rows[] = ['period' => $period, 'amount' => round($amount, 2)];
        }

        return $rows;
    }

    /**
     * 创建 PayPal 账户记录。
     *
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 email、accountName、addedDate、balance、reviews
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return array PayPal 账户结果数组，包含 id 等字段
     * @see PaypalDao::create()
     * @see PaypalDao::balance()
     * @see PaypalDao::review()
     * @see BusinessOperationLogDao::record()
     */
    public function create(array $data, int $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $email = strtolower(trim($data['email']));
            $activity = $this->activity();
            $account = $this->paypalDao->create([
                'email' => $email,
                'account_name' => $data['accountName'],
                'added_date' => $data['addedDate'] ?? null,
                'active' => true,
                'version' => 1,
                'meta' => [
                    'monitorNative' => true,
                    'baselineReceived' => $activity[$email]['amount'] ?? 0,
                    'baselineWithdrawed' => 0,
                ],
            ]);
            $this->paypalDao->balance($account->id, (float) $data['balance'], $actor);
            $this->paypalDao->review($account->id, (int) $data['reviews'], $actor);
            $this->businessOperationLogDao->record('paypal', (string) $account->id, 'create', $actor, null, $this->presentAccount($account, $activity));

            return ['id' => $account->id];
        });
    }

    /**
     * 更新 PayPal 账户记录。
     *
     * @param  int  $id  PayPal 账户记录主键 ID
     * @param  string  $action  要执行的业务操作标识
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 version、amount、value
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return array PayPal 账户结果数组，包含 id 等字段
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     * @see PaypalDao::lock()
     * @see PaypalDao::balance()
     * @see PaypalDao::review()
     * @see PaypalDao::withdrawal()
     * @see PaypalDao::save()
     * @see BusinessOperationLogDao::record()
     */
    public function update(
        int $id,
        string $action,
        array $data,
        int $actor,
    ): array {
        return DB::transaction(function () use ($id, $action, $data, $actor) {
            $account = $this->paypalDao->lock($id);
            abort_if($account->version !== $data['version'], 409, '账户已被修改，请刷新后重试');
            $activity = $this->activity();
            $before = $this->presentAccount($account, $activity);
            $meta = $account->meta ?? [];
            if ($action === 'balance') {
                $meta['baselineReceived'] = $activity[strtolower($account->email)]['amount'] ?? 0;
                $meta['baselineWithdrawed'] = $before['withdrawn'];
                $meta['monitorBalanceLocal'] = true;
                $this->paypalDao->balance($id, (float) $data['amount'], $actor);
            } elseif ($action === 'review') {
                $meta['monitorReviewLocal'] = true;
                $this->paypalDao->review($id, (int) $data['value'], $actor);
            } else {
                abort_if((float) $data['amount'] > $before['balance'], 422, '提款金额不得大于当前余额。');
                $this->paypalDao->withdrawal($id, $data, $actor);
            }
            $this->paypalDao->save($account, [
                'meta' => $meta,
                'version' => $account->version + 1,
            ]);
            $account->unsetRelations();
            $after = $this->presentAccount($account, $activity);
            $this->businessOperationLogDao->record('paypal', (string) $id, $action, $actor, $before, $after);

            return [
                'id' => $id,
                'version' => $account->version,
            ];
        });
    }

    /**
     * 旧账户缺少收款基线时，以最近一次余额登记时间回溯计算。
     *
     * @param  PaypalAccount  $account  PayPal 账户模型
     * @param  PaypalBalanceEntry|null  $balance  余额登记快照；null 表示不存在或尚未创建
     * @param  array  $activity  按收款账户归集的订单金额及明细
     * @return float 最近余额登记时已计入的累计美元收款金额
     */
    private function receivedBaseline(
        PaypalAccount $account,
        ?PaypalBalanceEntry $balance,
        array $activity,
    ): float {
        $meta = $account->meta ?? [];
        $baseline = $meta['baselineReceived'] ?? null;
        if ($baseline === null) {
            $baseline = 0;
            foreach ($activity[strtolower($account->email)]['orders'] ?? [] as $order) {
                if ($balance && strtotime($order['createTime']) <= $balance->created_at->timestamp) {
                    $baseline += $order['amountUsd'] ?? 0;
                }
            }
        }

        return (float) $baseline;
    }

    /**
     * 按最近登记余额及后续收款、提现计算账户当前余额。
     *
     * @param  PaypalAccount  $account  PayPal 账户模型
     * @param  array  $activity  按收款账户归集的订单金额及明细
     * @return array PayPal 账户结果数组；返回字段：id、email、accountName、addedDate、version、balance、received、withdrawn、reviews、latestIncomingAt、updatedAt
     * @see PaypalLegacyService::account()
     */
    private function presentAccount(PaypalAccount $account, array $activity): array
    {
        $balance = $account->balances->first();
        $received = $activity[strtolower($account->email)]['amount'] ?? 0;
        $withdrawn = (float) $account->withdrawals->sum('amount');
        $meta = $account->meta ?? [];
        $baseline = $this->receivedBaseline($account, $balance, $activity);
        $baselineWithdrawal = $meta['baselineWithdrawed'] ?? ($balance ? (float) $account->withdrawals
            ->filter(fn ($withdrawal) => $withdrawal->created_at <= $balance->created_at)
            ->sum('amount') : 0);
        $current = (float) ($balance?->balance ?? 0) + max(0, $received - $baseline) - max(0, $withdrawn - $baselineWithdrawal);
        $reviews = (int) ($account->reviews->first()?->review_count ?? 0);
        $legacyAccount = empty($meta['monitorNative']) ? $this->paypalLegacyService->account($account->email) : null;
        if ($legacyAccount) {
            $legacy = $this->paypalLegacyService;
            $newWithdrawn = (float) $account->withdrawals->where('id', '>', $legacy->catalog()['withdrawalWatermark'])->sum('amount');
            $manualWithdrawn = $legacy->localWithdrawn($account->email) + $newWithdrawn;
            $withdrawn = (float) ($legacyAccount['importedTotalWithdrew'] ?? 0) + $manualWithdrawn;
            if (!empty($meta['monitorBalanceLocal'])) {
                $current = (float) ($balance?->balance ?? 0) + max(0, $received - $baseline) - max(0, $withdrawn - $baselineWithdrawal);
            } else {
                $record = $legacy->balance($account->email);
                if ($record) {
                    $incoming = isset($record['baselineReceived']) ? max(0, $received - (float) $record['baselineReceived'])
                        : $this->incomingOutsideBaseline($activity[strtolower($account->email)]['orders'] ?? [], $record['baselineKeys'] ?? []);
                    $current = (float) ($record['base'] ?? 0) + $incoming - max(0, $manualWithdrawn - $legacy->baselineWithdrawn($account->email, $record));
                } else {
                    $importedBalance = $legacyAccount['currentBalance'] ?? $legacyAccount['balance'] ?? $legacyAccount['initialBalance'] ?? null;
                    // 未登记余额的旧账号不能把全部历史收款视为当前可用余额。
                    // 以恢复时点为界，仅叠加后续入账；管理员修正后改用明确的累计基线。
                    $restoredAt = strtotime($legacy->catalog()['restoredAt']);
                    $incoming = array_sum(array_map(
                        fn ($order) => (strtotime($order['createTime']) ?: 0) > $restoredAt ? $order['amountUsd'] : 0,
                        $activity[strtolower($account->email)]['orders'] ?? [],
                    ));
                    $current = (float) ($importedBalance ?? 0) + $incoming - $newWithdrawn;
                }
            }
            if (empty($meta['monitorReviewLocal'])) {
                $reviews = $legacy->reviews($account->email, $legacyAccount);
            }
        }

        return [
            'id' => $account->id,
            'email' => $account->email,
            'accountName' => $account->account_name,
            'addedDate' => $account->added_date,
            'version' => $account->version,
            'balance' => round($current, 2),
            'received' => round($received, 2),
            'withdrawn' => round($withdrawn, 2),
            'reviews' => $reviews,
            // OrderManagementService 已按下单时间倒序排列；保留时间偏移，前端按时间戳排序。
            'latestIncomingAt' => $activity[strtolower($account->email)]['orders'][0]['createTime'] ?? '',
            'updatedAt' => $balance?->created_at?->toIso8601String(),
        ];
    }

    /**
     * 兼容原系统尚未记录累计收款基线、仅保存订单键的账户。
     *
     * @param  array  $orders  用于累计收款的订单列表
     * @param  array  $baselineKeys  余额登记时已计入的订单去重键列表
     * @return float 未包含在历史去重键基线内的美元收款合计
     */
    private function incomingOutsideBaseline(array $orders, array $baselineKeys): float
    {
        $ignored = array_fill_keys($baselineKeys, true);
        $amount = 0;
        foreach ($orders as $order) {
            $key = implode('|', [$order['clientOrderId'] ?? '', $order['paypalOrderId'] ?? '',
                $order['createTime'] ?? '', $order['recipientPaypal'] ?? '', $order['amount'] ?: '', $order['currency'] ?? '']);
            if (!isset($ignored[$key])) {
                $amount += $order['amountUsd'];
            }
        }

        return round($amount, 2);
    }
}
