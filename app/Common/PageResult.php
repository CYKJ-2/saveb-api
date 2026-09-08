<?php

namespace App\Common;

/** 合并来源的业务列表在服务端分页，统计与导出继续使用完整筛选结果。 */
class PageResult
{
    public static function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }

    public static function fromRows(array $rows, array $filters): array
    {
        $total = count($rows);
        $size = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $lastPage = max(1, (int) ceil($total / $size));
        $page = min($lastPage, max(1, (int) ($filters['page'] ?? 1)));

        return [
            'list' => array_slice(array_values($rows), ($page - 1) * $size, $size),
            'total' => $total,
            'page' => $page,
            'per_page' => $size,
            'last_page' => $lastPage,
        ];
    }
}
