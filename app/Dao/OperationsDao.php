<?php

namespace App\Dao;

use App\Models\OnlineSpreadsheet;
use Illuminate\Database\Eloquent\Collection;

/**
 * 工作巡查数据访问：封装模型查询与持久化操作。
 */
class OperationsDao
{
    /**
     * 读取记录集合。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\OnlineSpreadsheet>
     */
    public function all(): Collection
    {
        return OnlineSpreadsheet::where('active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }
}
