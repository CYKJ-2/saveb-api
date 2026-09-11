<?php

namespace App\Console\Commands;

use App\Services\AnalysisImportService;
use Illuminate\Console\Command;

/** 从本地 XLSX 副本采集数据，无任何在线文档写操作。 */
class AnalysisImportCommand extends Command
{
    protected $signature = 'analysis:import {source : suppliers or procurement} {file : Local XLSX path} {--currency=CNY : CNY only} {--price-basis=row_total : Source row actual price} {--mode=current_month : current_month or initialize (first import only)}';

    protected $description = 'Import current-month procurement, initial history, or supplier mappings from a local XLSX copy';

    /**
     * 验证命令参数并执行本地副本采集。
     *
     * @param AnalysisImportService $service 带事务与重复导入检测的采集服务
     * @return int 成功为 SUCCESS；解析或持久化错误由 Artisan 输出且不激活半成品批次
     */
    public function handle(AnalysisImportService $service): int
    {
        $path = (string) $this->argument('file');
        $result = $service->import($path, basename($path), (string) $this->argument('source'), $this->option('currency'), (string) $this->option('price-basis'), null, (string) $this->option('mode'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
