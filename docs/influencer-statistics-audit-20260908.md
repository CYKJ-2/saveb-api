# 达人销量统计口径核对（2026-09-08）

## 核对方式

对照 saveb-source/dashboard/index.html 的 creator-dept 页面和 saveb-erp/api/src-ts/services/legacyDashboard.ts 的 influencerChartsForYear。将本地数据库中原接口需要的订单快照标量字段、上下文和网站归属，交给原项目已编译的 influencerChartsForYear 执行，独立复算后与当前 InfluencerService::report 比较。

所有数据库操作为只读。本次没有修改生产统计代码或业务数据。工具和对照结果保存在 E:/wwwroot/influencer-statistics-backup-20260908。

localhost:18088 当前无法连接，因此以下“原逻辑”指原项目代码在同一份本地数据库上计算的结果，不声称是线上当前页面数值。saveb-source 随代码保存的静态图表也较旧，七月只有 101 单，八月、九月为空，不能作为当前实时数据进行比较。

## 同源数据对照

| 月份 | 当前新系统单数 | 当前新系统 USD | 原逻辑单数 | 原逻辑 USD | 快照按网站和订单号去重后单数 | 快照按网站和订单号去重后 USD |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2026-07 | 366 | 129,938.41 | 224 | 85,586.86 | 390 | 141,510.92 |
| 2026-08 | 372 | 154,952.77 | 229 | 103,948.04 | 372 | 154,952.77 |
| 2026-09 | 125 | 57,672.65 | 56 | 29,766.75 | 126 | 59,171.60 |

## 差异原因

### 1. 原统计按全年订单号去重，未包括来源网站

原逻辑优先用 clientOrderId，否则 orderId，否则 paypalOrderId 构建 seenOrderKeys。全年共用集合，在判断达人归属前便登记订单号。不同网站的相同订单号会相互排斥，甚至会被更早的普通订单占用编号。

七月、八月、九月分别有 166、143、70 笔符合达人条件的快照订单被其他网站的相同编号排除；这些月份被排除的同网站重复数均为 0。八月移除这一错误去重后，新旧结果完全相同。

示例：davidlifestyle-saveb.com 在 2026-04-08 有订单 16203，导致 emarieblog-saveb.com 在 2026-09-01 的订单 16203（USD 338）被旧逻辑排除。

九月新系统当前纳入的 125 笔订单，按网站和订单号检查未发现重复。

### 2. 数据源和归属条件不同

原系统从 legacy_dashboard_days.payload.orders（不存在数组时回退 recent）读取，按快照 day 归月，并优先使用上下文/数据库的网站映射，最后回退订单 topInfluencer；不要求分类字段必须是达人分类。

新系统通过 OrderManagementService 读取 orders，按北京时间 order_time 归月，要求 classification 为 top_influencer 或 mid_influencer 且 influencer_name 不为空。

九月明确差异：taylor-brooke-saveb.net 订单 5230，金额 USD 1,506、4 件商品，influencer_name 为 Taylor Brooke，但 orders.classification 为 offline。原始 raw.classification 为 payment_link，raw.category/sourceCategory 为 Mid Influencers，未发现人工订单覆盖记录。原统计纳入，新统计排除。这是归属口径冲突，不宜直接覆盖原订单分类。

七月快照中还有 24 笔通过网站推断达人、但快照 topInfluencer 为空的记录，表明只检查已存达人名称可能漏归属。

原逻辑会把 David / David Coey 合并成 David Coey，并排除“网红A组”“Top Influencers”等泛化名称；新汇总没有实现这些规则。

### 3. 汇率口径不同

原系统使用 system_state.legacy_dashboard_context.exchangeRates，将原币金额除以“每美元对应原币数量”，逐单保留两位小数；未配置汇率时为 0。

新系统优先使用 orders.amount_usd，缺失才使用 exchange_rates 对应历史汇率乘算。

九月明确差异：davidlifestyle-saveb.co 订单 20713，EUR 437.79。原上下文 EUR 汇率 0.87252，得到 USD 501.75；订单入库 USD 508.80，相差 USD 7.05。

九月差额可以完整核对：

- 新系统 USD 57,672.65 + 订单 5230 的 USD 1,506 - 汇率差 USD 7.05 = 快照未误去重 USD 59,171.60。
- 再扣除被跨网站误去重的 70 单 USD 29,404.85 = 原逻辑 USD 29,766.75。

### 4. 状态边界

两边均识别 complete/completed/paid/success，且排除匹配 test 的测试客户。原逻辑额外允许空状态，新逻辑不允许。本次七月至九月符合达人归属的快照中未发现空状态，这不是当前三个月差额的原因。

## 建议口径

不要为匹配旧数值而复制跨网站订单号去重缺陷。以订单主表及真实订单身份作为基准，保留同号不同网站订单；明确达人业绩是“按网站归属”还是“按最终订单分类”，再统一实现；金额建议与现有订单列表及销售分析使用一致的入库 USD 口径。若改成旧上下文汇率，应统一相关页面而不是只让达人工作台另算一套。

上次页面迁移补齐了展示和网站目录，但销量仍沿用了订单管理的筛选、金额口径，未迁移上述原服务统计规则。本次已定位此差异，同时确认旧统计本身存在误去重问题。
