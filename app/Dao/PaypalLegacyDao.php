<?php

namespace App\Dao;

use App\Models\PaypalAccount;
use App\Models\PaypalWithdrawal;
use App\Models\SystemState;

/** 读取原平台共享状态并保存基础资料迁移标记。 */
class PaypalLegacyDao
{
    /**
     * 按状态键读取原平台共享数据。
     *
     * @param  string  $key  分组键或状态存储键
     * @return array 指定共享状态的 value；不存在时返回空数组
     */
    public function state(string $key): array
    {
        return SystemState::find($key)?->value ?? [];
    }

    /**
     * 保存恢复后的 PayPal 账户目录及迁移基线。
     *
     * @param  array  $catalog  恢复后的账户目录及迁移基线
     * @return void 无返回值；副作用见方法说明
     */
    public function saveCatalog(array $catalog): void
    {
        SystemState::updateOrCreate(['key' => 'paypal_monitor_catalog'], ['value' => $catalog]);
    }

    /**
     * 读取当前提款流水最大主键，作为资料恢复时的分界标识。
     *
     * @return int 当前提款记录最大 ID；无记录为 0
     */
    public function withdrawalWatermark(): int
    {
        return (int) PaypalWithdrawal::max('id');
    }

    /**
     * 检查新平台是否已有 PayPal 操作日志。
     *
     * @return bool 存在 PayPal 模块业务操作日志时为 true
     */
    public function localEditsExist(): bool
    {
        return \App\Models\BusinessOperationLog::where('module', 'paypal')->exists();
    }

    /**
     * 只补空的基础资料，保留新平台已设置的名称、日期和停用状态。
     *
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 email、addedDate、accountName
     * @return array created（新增条数）和 dated（补齐日期条数）
     */
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
