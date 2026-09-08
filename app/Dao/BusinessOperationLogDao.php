<?php

namespace App\Dao;

use App\Models\BusinessOperationLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 业务操作日志数据访问：封装模型查询与持久化操作。
 */
class BusinessOperationLogDao
{
    public function procurementExportLogs(): iterable
    {
        return BusinessOperationLog::whereIn('module', ['procurement', 'warehouse'])->orderByDesc('id')->cursor();
    }

    public function invoiceExportLogs(): iterable
    {
        return BusinessOperationLog::query()
            ->leftJoin('users', 'users.id', '=', 'business_operation_logs.actor_user_id')
            ->where('module', 'invoice')
            ->select('business_operation_logs.*', 'users.username as operator_name')
            ->orderByDesc('business_operation_logs.id')->cursor();
    }

    /**
     * 记录业务操作前后快照。
     */
    public function record(
        string $module,
        string $entity,
        string $action,
        int $actor,
        ?array $before,
        ?array $after,
    ): void {
        BusinessOperationLog::create([
            'module' => $module,
            'entity_id' => $entity,
            'action' => $action,
            'actor_user_id' => $actor,
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * 分页读取记录。
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<\App\Models\BusinessOperationLog>
     */
    public function list(string $module, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return BusinessOperationLog::where('module', $module)
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 读取记录集合。
     *
     * @return \Illuminate\Support\LazyCollection<int, \App\Models\BusinessOperationLog>
     */
    public function all(string $module): iterable
    {
        return BusinessOperationLog::where('module', $module)
            ->orderByDesc('id')
            ->cursor();
    }
}
