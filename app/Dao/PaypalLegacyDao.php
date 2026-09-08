<?php

namespace App\Dao;

use App\Models\PaypalAccount;
use App\Models\PaypalWithdrawal;
use App\Models\SystemState;

/** 读取原平台共享状态并保存基础资料迁移标记。 */
class PaypalLegacyDao
{
    public function state(string $key): array
    {
        return SystemState::find($key)?->value ?? [];
    }

    public function saveCatalog(array $catalog): void
    {
        SystemState::updateOrCreate(['key' => 'paypal_monitor_catalog'], ['value' => $catalog]);
    }

    public function withdrawalWatermark(): int
    {
        return (int) PaypalWithdrawal::max('id');
    }

    public function localEditsExist(): bool
    {
        return \App\Models\BusinessOperationLog::where('module', 'paypal')->exists();
    }

    /** 只补空的基础资料，保留新平台已设置的名称、日期和停用状态。 */
    public function restoreAccount(array $data): array
    {
        $account = PaypalAccount::withTrashed()->firstOrNew(['email' => $data['email']]);
        if ($account->trashed()) {
            return ['created' => 0, 'dated' => 0];
        }
        $created = !$account->exists;
        if ($created) {
            $account->fill(['active' => true, 'version' => 1, 'meta' => []]);
        }
        $dated = !$account->added_date && !empty($data['addedDate']);
        if ($dated) {
            $account->added_date = $data['addedDate'];
        }
        if (!$account->account_name) {
            $account->account_name = $data['accountName'] ?? null;
        }
        if ($account->isDirty()) {
            $account->save();
        }

        return ['created' => (int) $created, 'dated' => (int) $dated];
    }
}
