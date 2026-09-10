<?php

namespace App\Dao;

use App\Models\BusinessOperationLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * 业务操作日志数据访问：封装模型查询与持久化操作。
 */
class BusinessOperationLogDao
{
    /**
     * 分批读取采购及仓库日志，并关联操作人的显示名称和登录账号。
     *
     * @return iterable 按需迭代的业务操作日志记录，供逐条处理或导出
     */
    public function procurementExportLogs(): iterable
    {
        return $this->procurementLogQuery(true)->cursor();
    }

    /**
     * 分页读取采购日志及对应操作人名称。
     *
     * @param  int  $page  页码，从 1 开始
     * @param  int  $perPage  每页条数，默认 20
     * @return LengthAwarePaginator<BusinessOperationLog> 当前页采购日志及完整分页信息
     */
    public function procurementLogs(int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->procurementLogQuery()->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 关联操作人资料；保留已软删除用户的姓名，用户不存在时仍返回日志。
     *
     * @param  bool  $includeWarehouse  是否包含仓库日志；完整导出沿用旧采购日志的模块范围
     * @return Builder<BusinessOperationLog> 采购列表与导出共用的日志查询
     */
    private function procurementLogQuery(bool $includeWarehouse = false): Builder
    {
        return BusinessOperationLog::query()
            ->leftJoin('users', 'users.id', '=', 'business_operation_logs.actor_user_id')
            ->whereIn('business_operation_logs.module', $includeWarehouse ? ['procurement', 'warehouse'] : ['procurement'])
            ->select('business_operation_logs.*', 'users.username as operator_name', 'users.display_name as operator_display_name')
            ->orderByDesc('business_operation_logs.id');
    }

    /**
     * 创建 Invoice 日志查询，关联操作人的显示名称和登录账号。
     *
     * @return Builder<BusinessOperationLog> 列表与导出共用的查询；保留已删除用户的历史姓名
     */
    private function invoiceLogQuery(): Builder
    {
        return BusinessOperationLog::query()
            ->leftJoin('users', 'users.id', '=', 'business_operation_logs.actor_user_id')
            ->where('business_operation_logs.module', 'invoice')
            ->select('business_operation_logs.*', 'users.username as operator_name', 'users.display_name as operator_display_name')
            ->orderByDesc('business_operation_logs.id');
    }

    /**
     * 分页读取 Invoice 日志及对应操作人名称。
     *
     * @param  int  $page  页码，从 1 开始
     * @param  int  $perPage  每页条数，默认 20
     * @return LengthAwarePaginator<BusinessOperationLog> 当前页日志及完整分页信息
     */
    public function invoiceLogs(int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->invoiceLogQuery()->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 分批读取 Invoice 日志，并补充操作人名称。
     *
     * @return iterable 按需迭代的业务操作日志记录，供逐条处理或导出
     */
    public function invoiceExportLogs(): iterable
    {
        return $this->invoiceLogQuery()->cursor();
    }

    /**
     * 记录业务操作前后快照。
     *
     * @param  string  $module  要查询的统计或业务模块标识
     * @param  string  $entity  操作日志关联的业务记录标识
     * @param  string  $action  要执行的业务操作标识
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @param  array|null  $before  操作前的业务快照；null 表示无旧记录
     * @param  array|null  $after  操作后的业务快照；null 表示无新记录
     * @return void 无返回值；副作用见方法说明
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
     * @param  string  $module  要查询的统计或业务模块标识
     * @param  int  $page  页码，从 1 开始；默认 1
     * @param  int  $perPage  每页条数；默认 20
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<\App\Models\BusinessOperationLog> 业务操作日志分页器，包含当前页记录、总条数和分页信息
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
     * @param  string  $module  要查询的统计或业务模块标识
     * @return \Illuminate\Support\LazyCollection<int, \App\Models\BusinessOperationLog> 业务操作日志查询或计算结果集合；无匹配时为空集合
     */
    public function all(string $module): iterable
    {
        return BusinessOperationLog::where('module', $module)
            ->orderByDesc('id')
            ->cursor();
    }
}
