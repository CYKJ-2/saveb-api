<?php

namespace App\Models;

/**
 * 在线协作表格模型：定义数据表、字段转换及关联关系。
 */
class OnlineSpreadsheet extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'online_spreadsheets';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /**
     * 定义字段类型转换。
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
