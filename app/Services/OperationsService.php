<?php

namespace App\Services;

use App\Dao\OperationsDao;

/**
 * 工作巡查服务：处理业务规则、统计口径和事务。
 */
class OperationsService
{
    private const DEPARTMENTS = [
        'customer-service' => ['客服部', 'Customer Service'],
        'purchasing' => ['采购部', 'Purchasing'],
        'warehouse' => ['仓储部', 'Warehouse'],
        'operations' => ['运营部', 'Operations'],
        'influencer' => ['达人部', 'Influencer'],
        'finance' => ['财务部', 'Finance'],
    ];

    public function __construct(private OperationsDao $operationsDao)
    {
    }

    /**
     * 查询目录。
     */
    public function directory(array $filters): array
    {
        return $this->report($filters)['rows'];
    }

    /** 原目录固定展示六个部门（含空部门），数量不受关键词过滤影响。 */
    public function report(array $filters): array
    {
        $rows = $this->operationsDao->all()->filter(function ($row) {
            return filter_var($row->url, FILTER_VALIDATE_URL)
                && strtolower((string) parse_url($row->url, PHP_URL_SCHEME)) === 'https';
        })->map(fn ($row) => $row->only(['id', 'source_key', 'department', 'provider', 'title_zh', 'title_en',
            'description_zh', 'description_en', 'url']))->values()->all();
        $departments = [];
        foreach (self::DEPARTMENTS as $code => [$nameZh, $nameEn]) {
            $departments[] = ['code' => $code, 'name_zh' => $nameZh, 'name_en' => $nameEn,
                'count' => count(array_filter($rows, fn ($row) => $row['department'] === $code))];
        }
        $keyword = trim($filters['keyword'] ?? '');
        $filtered = array_values(array_filter($rows, function ($row) use ($filters, $keyword) {
            if (!empty($filters['department']) && $filters['department'] !== $row['department']) {
                return false;
            }
            $department = self::DEPARTMENTS[$row['department']] ?? [$row['department']];
            $searchText = implode(' ', [$row['title_zh'], $row['title_en'], $row['description_zh'],
                $row['description_en'], $row['provider'], ...$department]);

            return $keyword === '' || mb_stripos($searchText, $keyword) !== false;
        }));

        return ['rows' => $filtered, 'departments' => $departments, 'total' => count($rows), 'matched' => count($filtered)];
    }
}
