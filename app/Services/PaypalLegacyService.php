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

    public function __construct(private PaypalLegacyDao $paypalLegacyDao)
    {
    }

    public function catalog(): array
    {
        return $this->catalog ??= $this->paypalLegacyDao->state('paypal_monitor_catalog');
    }

    public function state(): array
    {
        return $this->state ??= $this->paypalLegacyDao->state('paypal_legacy_state');
    }

    public function account(string $email): ?array
    {
        return $this->catalog()['accounts'][strtolower(trim($email))] ?? null;
    }

    public function balance(string $email): ?array
    {
        return $this->state()['balances'][strtolower(trim($email))] ?? null;
    }

    public function reviews(string $email, array $account): int
    {
        $saved = $this->state()['reviews'][strtolower(trim($email))] ?? null;

        return max(0, (int) (is_array($saved) ? ($saved['value'] ?? 0) : ($saved ?? $account['numberOfReviews'] ?? $account['reviews'] ?? $account['reviewCount'] ?? 0)));
    }

    /** 原平台优先使用 createdAt，其次 date；不得用数据库导入时间替代。 */
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
            $rows[] = ['id' => 'legacy:' . $email . ':' . $index, 'date' => substr($timestamp, 0, 10),
                'amount' => round((float) $entry['amount'], 2), 'source' => '', 'timestamp' => $timestamp, 'imported' => false];
        }

        return $rows;
    }

    public function localWithdrawn(string $email): float
    {
        $entries = $this->entries($email, true);
        if ($entries || !empty($this->catalog()['totalWithdrewImportedAt'])) {
            return round(array_sum(array_column($entries, 'amount')), 2);
        }
        $record = $this->state()['withdrawals'][strtolower(trim($email))] ?? 0;

        return (float) (is_array($record) ? ($record['total'] ?? 0) : $record);
    }

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

    /** 可重复执行的基础资料恢复，不重置角色、余额登记或提款表。 */
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

    private function date(string $value): ?string
    {
        if (!preg_match('/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})/', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]);
    }

    /** 无时区的导入日期按原页面的北京时间解释。 */
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
