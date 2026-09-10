<?php

namespace App\Services;

use App\Dao\BusinessOperationLogDao;
use App\Dao\InfluencerDao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 达人服务：处理业务规则、统计口径和事务。
 */
class InfluencerService
{
    /**
     * 网站列表分页，目录概要按全量计算，搜索不会改变来源数量。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 keyword
     * @return array 当前页记录及分页信息；汇总字段按业务方法计算
     */
    public function directoryPage(array $filters): array
    {
        $all = $this->directory();
        $rows = $this->directory(trim($filters['keyword'] ?? ''));
        $domains = array_merge([], ...array_column($all, 'domains'));
        $dates = array_filter(array_column($domains, 'updatedAt'));

        return \App\Common\PageResult::fromRows($rows, $filters) + [
            'summary' => [
                'influencers' => count($all),
                'websites' => count($domains),
                'updatedAt' => $dates ? max($dates) : null,
            ],
        ];
    }

    /**
     * 新增网站只需要达人名称选项，不加载整个网站目录。
     *
     * @return array 可用于网站绑定的达人名称列表
     */
    public function options(): array
    {
        return array_map(fn ($group) => ['name' => $group['name']], $this->directory());
    }

    /**
     * 排行榜服务端分页，图表独立保留前 30 名及完整汇总。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 month
     * @return array 当前页记录及分页信息；汇总字段按业务方法计算
     */
    public function reportPage(array $filters): array
    {
        $report = $this->report($filters['month']);
        $page = \App\Common\PageResult::fromRows($report['rows'], $filters);
        $report['chart'] = array_slice($report['rows'], 0, 30);
        unset($report['rows']);

        return $page + $report;
    }

    /**
     * 兼容销售列表入口也按请求分页，避免绕过月报分页拉取全量排行。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件
     * @return array 当前页记录及分页信息；汇总字段按业务方法计算
     */
    public function salesPage(array $filters): array
    {
        return \App\Common\PageResult::fromRows($this->sales($filters), $filters);
    }

    /**
     * 注入 达人与网站处理所需的依赖。
     *
     * @param  InfluencerDao  $influencerDao  达人与网站数据访问对象
     * @param  OrderManagementService  $orderManagementService  订单管理业务服务
     * @param  BusinessOperationLogDao  $businessOperationLogDao  业务操作日志数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(
        private InfluencerDao $influencerDao,
        private OrderManagementService $orderManagementService,
        private BusinessOperationLogDao $businessOperationLogDao,
    ) {
    }

    /**
     * 查询达人与网站目录。
     *
     * @param  string  $keyword  列表关键字；空字符串表示不按关键字过滤；默认 ''
     * @return array 匹配关键字的达人网站目录记录
     * @see InfluencerDao::rules()
     * @see InfluencerDao::domains()
     * @see InfluencerDao::profiles()
     */
    public function directory(string $keyword = ''): array
    {
        $domains = [];
        $rules = $this->influencerDao->rules();
        foreach ($rules['domains'] ?? [] as $domain => $name) {
            $domain = $this->normalizeDomain($domain);
            if ($domain !== '' && trim($name) !== '') {
                $domains[$domain] = ['id' => null, 'domain' => $domain, 'name' => trim($name), 'confirmed' => false, 'updatedAt' => null];
            }
        }
        foreach ($this->influencerDao->domains() as $row) {
            $domain = $this->normalizeDomain($row->domain);
            unset($domains[$domain]);
            if (!$row->trashed() && $domain !== '' && trim($row->influencer_name ?? '') !== '') {
                $domains[$domain] = ['id' => $row->id, 'domain' => $domain, 'name' => trim($row->influencer_name), 'confirmed' => $row->confirmed, 'updatedAt' => $row->updated_at?->toIso8601String()];
            }
        }
        ksort($domains);
        $profiles = $this->influencerDao->profiles();
        $groups = [];
        foreach ($domains as $domain) {
            $key = $domain['name'];
            $tier = $profiles->get($key)?->profile['tier'] ?? $rules['tiers'][$key] ?? '';
            $groups[$key] ??= [
                'name' => $key,
                'tier' => in_array($tier, ['top', 'mid'], true) ? $tier : '',
                'domains' => [],
            ];
            unset($domain['name']);
            $groups[$key]['domains'][] = $domain;
        }
        // 搜索命中网站时仍返回该达人的完整网站列表，与原页面一致。
        $groups = array_filter($groups, fn ($group) => $keyword === '' || mb_stripos($group['name'] . ' ' . implode(' ', array_column($group['domains'], 'domain')), trim($keyword)) !== false);
        uasort($groups, function ($first, $second) {
            $tiers = ['top' => 0, 'mid' => 1, '' => 2];

            return ($tiers[$first['tier']] <=> $tiers[$second['tier']]) ?: strcasecmp($first['name'], $second['name']);
        });

        return array_values($groups);
    }

    /**
     * 月报同时提供汇总、完整排行榜和真实数据覆盖时间。
     *
     * @param  string  $month  统计月份，格式 Y-m
     * @return array 达人与网站结果数组；返回字段：month、rows、totals、meta
     * @see InfluencerDao::latestOrderTimes()
     */
    public function report(string $month): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Shanghai');
        $rows = $this->sales(['startDate' => $date->startOfMonth()->toDateString(), 'endDate' => $date->endOfMonth()->toDateString()]);

        return [
            'month' => $month,
            'rows' => $rows,
            'totals' => [
                'amountUsd' => round(array_sum(array_column($rows, 'amountUsd')), 2),
                'orders' => array_sum(array_column($rows, 'orders')),
                'items' => array_sum(array_column($rows, 'items')),
            ],
            'meta' => $this->influencerDao->latestOrderTimes() + ['queriedAt' => now()->toIso8601String()],
        ];
    }

    /**
     * 汇总销售业绩。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件
     * @return array 指定月份的达人销售汇总与排行数据
     * @see InfluencerDao::profiles()
     * @see InfluencerDao::rules()
     * @see OrderManagementService::rows()
     */
    public function sales(array $filters): array
    {
        $groups = [];
        foreach ($this->directory() as $row) {
            $groups[$row['name']] = [
                'name' => $row['name'],
                'amountUsd' => 0,
                'orders' => 0,
                'items' => 0,
            ];
        }
        $profiles = $this->influencerDao->profiles();
        foreach (array_unique(array_merge(array_keys($this->influencerDao->rules()['tiers'] ?? []), $profiles->keys()->all())) as $name) {
            $groups[$name] ??= ['name' => $name, 'amountUsd' => 0, 'orders' => 0, 'items' => 0];
        }
        foreach ($this->orderManagementService->rows($filters + ['orderStatus' => 'completed']) as $row) {
            if (!in_array($row['classification'], ['top_influencer', 'mid_influencer']) || !$row['topInfluencer']) {
                continue;
            }
            $key = $row['topInfluencer'];
            $groups[$key] ??= [
                'name' => $key,
                'amountUsd' => 0,
                'orders' => 0,
                'items' => 0,
            ];
            $groups[$key]['amountUsd'] += $row['amountUsd'] ?? 0;
            $groups[$key]['orders']++;
            $groups[$key]['items'] += $row['items'];
        }
        foreach ($groups as &$row) {
            $profile = $profiles->get($row['name'])?->profile ?? [];
            $row['amountUsd'] = round($row['amountUsd'], 2);
            $row['sampleQuantity'] = $profile['sampleQuantity'] ?? null;
            // 历史资料存于 JSON，金额没有数据库 decimal 字段的精度约束。
            foreach (['sampleValue', 'commissionAmount'] as $field) {
                $value = $profile[$field] ?? null;
                $row[$field] = is_numeric($value)
                    ? (is_string($value) ? number_format((float) $value, 2, '.', '') : round($value, 2))
                    : $value;
            }
        }
        unset($row);
        uasort($groups, fn ($firstRow, $secondRow) => ($secondRow['amountUsd'] <=> $firstRow['amountUsd']) ?: strcasecmp($firstRow['name'], $secondRow['name']));

        return array_values($groups);
    }

    /**
     * 保存达人与网站及其关联数据。
     *
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 domain、influencer
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return array 保存后的达人网站归属记录
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     * @see InfluencerDao::domain()
     * @see InfluencerDao::rules()
     * @see InfluencerDao::saveDomain()
     * @see BusinessOperationLogDao::record()
     */
    public function save(array $data, int $actor): array
    {
        $domain = $this->normalizeDomain($data['domain']);
        abort_if($domain === '', 422, '请输入有效网站域名');

        return DB::transaction(function () use ($domain, $data, $actor) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['influencer:' . $domain]);
            $before = $this->influencerDao->domain($domain);
            $sourceOwner = $this->influencerDao->rules()['domains'][$domain] ?? null;
            if (!$before && $sourceOwner && $sourceOwner !== trim($data['influencer'])) {
                abort(409, '此网站已分配给其他达人，请先核对归属');
            }
            if ($before && !$before->trashed() && $before->influencer_name !== trim($data['influencer'])) {
                abort(409, '此网站已分配给其他达人，请先核对归属');
            }
            $added = !$before || $before->trashed();
            $row = $this->influencerDao->saveDomain($domain, trim($data['influencer']));
            $this->businessOperationLogDao->record('influencer', (string) $row->id, 'assign_domain', $actor, $before?->toArray(), $row->toArray());

            return $row->toArray() + ['added' => $added && !$sourceOwner];
        });
    }

    /**
     * 与原页面统一去除协议、www、路径、查询参数和末尾句点。
     *
     * @param  string  $value  待归一化的原始值
     * @return string 不含协议、www 和路径的有效域名；无效输入返回空字符串
     */
    private function normalizeDomain(string $value): string
    {
        $domain = preg_replace('#^https?://#', '', strtolower(trim($value)));
        $domain = preg_split('#[/?\#]#', $domain)[0];
        $domain = rtrim(preg_replace('#^www\.#', '', $domain), '.');

        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && preg_match('/\.[a-z]{2,}$/', $domain) ? $domain : '';
    }
}
