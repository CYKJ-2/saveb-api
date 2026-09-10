<?php

namespace App\Services;

use App\Dao\PaypalLegacyDao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 迁移原平台基础资料；共享状态中的历史余额、基线、累计提款独立保留。
 * 新平台提款以切换时的流水 ID 为界叠加，避免再次计算已导入的历史流水。
 */
class PaypalLegacyService
{
    private ?array $catalog = null;

    private ?array $state = null;

    /**
     * 注入 PayPal 历史资料处理所需的依赖。
     *
     * @param  PaypalLegacyDao  $paypalLegacyDao  PayPal 历史资料数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private PaypalLegacyDao $paypalLegacyDao)
    {
    }

    /**
     * 读取已恢复的 PayPal 静态账户目录。
     *
     * @return array 静态与自定义账户目录，以及资料恢复时记录的导入分界
     * @see PaypalLegacyDao::state()
     */
    public function catalog(): array
    {
        return $this->catalog ??= $this->paypalLegacyDao->state('paypal_monitor_catalog');
    }

    /**
     * 读取原平台 PayPal 共享状态。
     *
     * @return array 历史余额、审核次数、自定义账户与提款共享状态
     * @see PaypalLegacyDao::state()
     */
    public function state(): array
    {
        return $this->state ??= $this->paypalLegacyDao->state('paypal_legacy_state');
    }

    /**
     * 按邮箱查找历史自定义账户基础资料。
     *
     * @param  string  $email  PayPal 收款账户邮箱
     * @return array|null 指定邮箱的历史账户资料；未登记时为 null
     */
    public function account(string $email): ?array
    {
        return $this->catalog()['accounts'][strtolower(trim($email))] ?? null;
    }

    /**
     * 按邮箱读取原平台最近登记的余额快照。
     *
     * @param  string  $email  PayPal 收款账户邮箱
     * @return array|null 指定邮箱的历史余额登记快照；未登记时为 null
     */
    public function balance(string $email): ?array
    {
        return $this->state()['balances'][strtolower(trim($email))] ?? null;
    }

    /**
     * 合并历史审核记录与账户资料中的审核次数。
     *
     * @param  string  $email  PayPal 收款账户邮箱
     * @param  array  $account  PayPal 账户模型
     * @return int 历史审核次数，最小为 0
     */
    public function reviews(string $email, array $account): int
    {
        $saved = $this->state()['reviews'][strtolower(trim($email))] ?? null;

        return max(0, (int) (is_array($saved) ? ($saved['value'] ?? 0) : ($saved ?? $account['numberOfReviews'] ?? $account['reviews'] ?? $account['reviewCount'] ?? 0)));
    }

    /**
     * 按提款业务日期展示；创建时间仍用于导入分界和余额基线判断。
     *
     * @param  string  $email  PayPal 收款账户邮箱
     * @param  bool  $afterImport  是否只读取导入分界后的历史提款；默认 false
     * @return array 按业务日期呈现的历史提款列表，保留创建时间供基线判断
     */
    public function entries(string $email, bool $afterImport = false): array
    {
        $record = $this->state()['withdrawals'][strtolower(trim($email))] ?? [];
        $entries = !is_array($record) ? [] : (array_is_list($record) ? $record : ($record['entries'] ?? []));
        $cutoff = $this->timestamp($this->catalog()['totalWithdrewImportedAt'] ?? '');
        $rows = [];
        foreach ($entries as $index => $entry) {
            $timestamp = $entry['createdAt'] ?? $entry['date'] ?? '';
            if ($afterImport && $cutoff && $this->timestamp($timestamp) <= $cutoff) {
                continue;
            }
            if (!(float) ($entry['amount'] ?? 0)) {
                continue;
            }
            $rows[] = ['id' => 'legacy:' . $email . ':' . $index, 'date' => $this->withdrawalDate($entry),
                'amount' => round((float) $entry['amount'], 2), 'source' => '', 'timestamp' => $timestamp, 'imported' => false];
        }

        return $rows;
    }

    /**
     * 统计导入分界后的历史共享状态提款金额。
     *
     * @param  string  $email  PayPal 收款账户邮箱
     * @return float 导入分界后的历史提款合计；无分界及明细时兼容累计值
     */
    public function localWithdrawn(string $email): float
    {
        $entries = $this->entries($email, true);
        if ($entries || !empty($this->catalog()['totalWithdrewImportedAt'])) {
            return round(array_sum(array_column($entries, 'amount')), 2);
        }
        $record = $this->state()['withdrawals'][strtolower(trim($email))] ?? 0;

        return (float) (is_array($record) ? ($record['total'] ?? 0) : $record);
    }

    /**
     * 读取余额快照的提款基线，缺失时按余额登记时点回溯已累计提款。
     *
     * @param  string  $email  PayPal 收款账户邮箱
     * @param  array  $balance  余额登记快照
     * @return float 余额快照已计入的累计提款基线
     */
    public function baselineWithdrawn(string $email, array $balance): float
    {
        if (isset($balance['baselineWithdrawed'])) {
            return (float) $balance['baselineWithdrawed'];
        }
        $cutoff = $this->timestamp($balance['updatedAt'] ?? '');

        return round(array_sum(array_column(array_filter(
            $this->entries($email, true),
            fn ($entry) => $this->timestamp($entry['timestamp']) <= $cutoff,
        ), 'amount')), 2);
    }

    /**
     * 可重复执行的基础资料恢复，不重置角色、余额登记或提款表。
     *
     * @param  array  $legend  原静态账户目录资料
     * @param  bool  $apply  是否实际保存恢复结果；false 仅预览
     * @return array 账户总数、可用日期数、新增账户数及补齐日期数
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     * @see PaypalLegacyDao::localEditsExist()
     * @see PaypalLegacyDao::saveCatalog()
     * @see PaypalLegacyDao::withdrawalWatermark()
     * @see PaypalLegacyDao::restoreAccount()
     */
    public function restore(array $legend, bool $apply): array
    {
        $accounts = [];
        foreach (array_merge($this->state()['customAccounts'] ?? [], $legend['rows'] ?? []) as $row) {
            $email = strtolower(trim($row['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($accounts[$email])) {
                continue;
            }
            $row['email'] = $email;
            $row['addedDate'] = $this->date($row['addedDate'] ?? '');
            $accounts[$email] = $row;
        }
        $result = ['accounts' => count($accounts), 'dates' => count(array_filter(array_column($accounts, 'addedDate'))), 'created' => 0, 'dated' => 0];
        if (!$apply) {
            return $result;
        }

        return DB::transaction(function () use ($accounts, $legend, $result) {
            $existing = $this->catalog();
            $sourceHash = hash('sha256', json_encode($legend));
            abort_if($existing && ($existing['sourceSha256'] ?? '') !== $sourceHash, 409, '基础资料版本已变化，需先核对差异再更新');
            // 首次接入前若已有本地财务修改，需要先逐笔对账，不能用旧快照覆盖。
            abort_if(!$existing && $this->paypalLegacyDao->localEditsExist(), 409, '存在新平台 PayPal 修改记录，需先完成迁移对账');
            if (!$existing) {
                $this->paypalLegacyDao->saveCatalog([
                    'accounts' => $accounts,
                    'totalWithdrewImportedAt' => $legend['totalWithdrewImportedAt'] ?? '',
                    'withdrawalWatermark' => $this->paypalLegacyDao->withdrawalWatermark(),
                    'restoredAt' => now()->toIso8601String(),
                    'sourceSha256' => $sourceHash,
                ]);
            }
            foreach ($accounts as $account) {
                $changes = $this->paypalLegacyDao->restoreAccount($account);
                $result['created'] += $changes['created'];
                $result['dated'] += $changes['dated'];
            }
            $this->catalog = null;

            return $result;
        });
    }

    /**
     * 优先采用登记的提款日期；旧记录缺少日期时，创建时间按北京时间归日。
     *
     * @param  array  $entry  历史提款记录
     * @return string Y-m-d 提款业务日期；无法解析时为空字符串
     */
    private function withdrawalDate(array $entry): string
    {
        $businessDate = $this->date((string) ($entry['date'] ?? ''));
        if ($businessDate !== null) {
            return $businessDate;
        }

        $createdAt = trim((string) ($entry['createdAt'] ?? ''));
        if ($createdAt === '') {
            return '';
        }
        try {
            return CarbonImmutable::parse($createdAt, 'Asia/Shanghai')
                ->setTimezone('Asia/Shanghai')
                ->toDateString();
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * 解析历史账户日期，转换为标准业务日期。
     *
     * @param  string  $value  待归一化的原始值
     * @return string|null 标准 Y-m-d 日期；格式或年月日无效时为 null
     */
    private function date(string $value): ?string
    {
        if (!preg_match('/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})/', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]);
    }

    /**
     * 无时区的导入日期按原页面的北京时间解释。
     *
     * @param  string  $value  待归一化的原始值
     * @return int Unix 秒时间戳；空值或解析失败时为 0
     */
    private function timestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        try {
            return CarbonImmutable::parse($value, 'Asia/Shanghai')->timestamp;
        } catch (\Exception) {
            return 0;
        }
    }
}
