<?php

namespace App\Console\Commands;

use App\Services\PaypalLegacyService;
use Illuminate\Console\Command;

class RestorePaypalCatalog extends Command
{
    protected $signature = 'paypal:restore-legacy-catalog {path : 原 paypal_legend_accounts.json 文件} {--apply : 写入基础资料与迁移标记}';

    protected $description = '预览或恢复原平台 PayPal 账号基础资料';

    public function handle(PaypalLegacyService $paypalLegacyService): int
    {
        $path = $this->argument('path');
        if (!is_file($path)) {
            $this->error('基础资料文件不存在');

            return self::FAILURE;
        }
        $legend = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($legend['rows'] ?? null) || !$legend['rows']) {
            $this->error('基础资料 rows 为空或格式不正确');

            return self::FAILURE;
        }
        $this->line(json_encode($paypalLegacyService->restore($legend, (bool) $this->option('apply')), JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
