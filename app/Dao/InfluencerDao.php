<?php

namespace App\Dao;

use App\Models\Influencer;
use App\Models\InfluencerDomain;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

/**
 * 达人数据访问：封装模型查询与持久化操作。
 */
class InfluencerDao
{
    /**
     * 从随项目迁移的配置读取原系统静态规则，不依赖原服务运行。
     *
     * @return array 项目迁移配置中的达人网站分类规则
     */
    public function rules(): array
    {
        return config('influencer', []);
    }

    /**
     * 关联网站目录。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\InfluencerDomain> 达人与网站查询或计算结果集合；无匹配时为空集合
     */
    public function domains(): Collection
    {
        return InfluencerDomain::withTrashed()->orderBy('influencer_name')
            ->orderBy('domain')
            ->get();
    }

    /**
     * 按达人显示名称索引档案，供目录与销量汇总关联。
     *
     * @return \Illuminate\Database\Eloquent\Collection<string, \App\Models\Influencer> 达人与网站查询或计算结果集合；无匹配时为空集合
     */
    public function profiles(): Collection
    {
        return Influencer::all()->keyBy('display_name');
    }

    /**
     * 已入库订单的覆盖时间，不能将查询时间当成采集更新时间。
     *
     * @return array 达人与网站结果数组；返回字段：latestOrderAt、lastInfluencerOrderAt
     */
    public function latestOrderTimes(): array
    {
        return [
            'latestOrderAt' => Order::max('order_time'),
            'lastInfluencerOrderAt' => Order::whereIn('classification', ['top_influencer', 'mid_influencer'])->max('order_time'),
        ];
    }

    /**
     * 加锁读取网站归属，包含已删除记录以便恢复绑定。
     *
     * @param  string  $domain  待查询或绑定的来源网站域名
     * @return InfluencerDomain|null 达人与网站模型实例；未找到时返回 null
     */
    public function domain(string $domain): ?InfluencerDomain
    {
        return InfluencerDomain::withTrashed()
            ->where('domain', $domain)
            ->lockForUpdate()
            ->first();
    }

    /**
     * 创建或恢复网站归属，并标记为已确认。
     *
     * @param  string  $domain  待查询或绑定的来源网站域名
     * @param  string  $name  当前业务对象的名称
     * @return InfluencerDomain 达人与网站模型实例
     */
    public function saveDomain(string $domain, string $name): InfluencerDomain
    {
        return InfluencerDomain::withTrashed()
            ->updateOrCreate(['domain' => $domain], [
                'influencer_name' => $name,
                'confirmed' => true,
                'deleted_at' => null,
            ]);
    }
}
