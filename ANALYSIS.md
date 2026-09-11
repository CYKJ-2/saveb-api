# Analysis 采购成交分析

页面：[Analysis](http://localhost:3000/workbench/analysis)  
接口预览：[API 文档](http://localhost:8080/api-docs/index.html)（搜索 Analysis）

## 统计口径

唯一事实源为上传的采购集成表，供应商表提供品牌代号、供应商与品类映射；不读取订单、Invoice、收单或 PayPal 业务表。

- 币种：人民币 CNY。
- 成交记录：原表“是否采购”为“已采购”，且“实际成交价格”是有效 CNY 数字。
- 金额：每行原始实际成交价格直接汇总，使用 PostgreSQL NUMERIC 和十进制计算，保留两位。不会从货号、描述或单号推算数量，也不会乘商品数量。
- 历史旧表仅有“价格”列时兼容读取并加核查提示。无效价格、公式错误、空值保留原文，数值为 NULL，不当成零；零和负数保留为有效数值，负数独立区间。
- 趋势日期：发起采购日期。顾客下单日期仅保留展示，不自动替代。缺日期的有效金额计入无日期限制的总览，但无法进入趋势；页面明确提示差额。
- 月份版本：按采购 Sheet 名称（如 2026.09）确定 source_period，与采购日期分别存储。日期跨月份会标记核查问题，但不静默改写。
- 顾客类型：按客户名称 NFKC、忽略大小写、合并空白后的相同名称识别。只使用已采购且价格有效的完整已导入历史；最早采购日的全部行是首购，之后日期为复购。缺名或日期为未知。时间、品牌、品类等筛选不会重新定义首购日。这是“已导入历史”的首购，不代表客户一生第一次购物；同名客户可能被合并。
- 采购方式：从“下单形式／单号”原文标记提取 WS、PL、Invoice、售后/换补货、达人/样品、配件；不能确认的保留未知。
- 当前价格区间：负数、0–99.99、100–299.99、300–499.99、500–999.99、1000–2999.99、3000+。
- 品牌/品类占比以全部筛选金额作为分母，保留待分类组；负数不进入饼图，完整金额保留在排行表。
- “品类 × 价格区间”“首购／复购 × 品牌”和“首购／复购 × 品类”展示行合计、列合计、总成交金额及占比。各单元格和合计的占比均为该金额 ÷ 当前筛选总成交金额 × 100，包含待分类与未知组；总额为零时显示 0.00%，负数保留符号，四舍五入后的占比相加可能不等于 100%。行列合计直接复用接口 `distributions` 的对应完整维度，总计使用 `summary`；不额外请求或在前端累加金额。
- 页面不再展示“品牌 × 品类”；其接口字段保留兼容。上述三个交叉表及“采购明细与数据核查”默认收起，可独立展开。明细首次展开后才加载，收起期间筛选变化在下次展开时更新。所有金额占比配比例条及两位百分比，满格为 100%，负数标红；超过 100% 时保留实际数值，条形长度封顶。

## 表结构

四张业务表，加一张技术导入批次表。统计接口从采购明细的 ID 与名称快照直接分组，避免每次汇总联表匹配。

### analysis_procurement_rows — 采购明细

| 字段组 | 主要字段 | 用途 |
| --- | --- | --- |
| 来源位置 | id、import_id、source_period、sheet_name、row_number、row_hash | 确定文件批次、月份及原始行 |
| 原始数据 | raw JSONB、order_reference、brand_raw、supplier_raw、product_description、customer_name、price_raw、price_column、purchase_status | 原始单元格、公式文本及缓存、来源列和人工标记 |
| 日期 | customer_order_date、procurement_date、analysis_date、date_basis | 原下单日、发起采购日及统计依据 |
| 金额 | actual_price NUMERIC(18,2)、analysis_amount NUMERIC(20,2)、supplier_quote NUMERIC(18,2)、currency、price_status、price_basis | 有效原价、符合条件的统计金额、供应商定价；固定 CNY/row_total |
| 品牌绑定 | brand_id、brand_name、brand_name_en、brand_match_status | 品牌主键和双语快照；未确认使用同一“待分类”品牌 |
| 品类绑定 | category_id、category_code、category_name、category_name_en、classification_status | 由供应商/代号匹配，无法确认时绑定待分类品类 |
| 供应商绑定 | supplier_id、supplier_name | 原表出现的新供应商也保留字典主键，不猜测关系 |
| 客户与采购方式 | customer_key、customer_first_date、customer_type、purchase_method | 全历史首复购及原文采购方式 |
| 核查依据 | classification_evidence JSONB、issues JSONB、mapping_import_id、mapped_at | 规则来源 Sheet/行号、候选、歧义、使用的字典版本 |
| 快照状态 | is_current、is_eligible、created_at、updated_at | 当前月份版本及是否纳入成交统计 |

唯一约束：(import_id, sheet_name, row_number)。即使同月两行完全相同，也保留为两条原始采购记录，不按订单号擅自去重。以 is_current 为条件建立采购日期、月份、品牌、品类、客户及筛选索引。

### analysis_brands — 品牌字典

id、identity（规范品牌名摘要，唯一）、name_zh、name_en、aliases JSONB（代号）、source_entries JSONB（无具体供应商的定义/待确认来源）、is_active、时间戳。

品牌待确认先统一绑定“待分类 / Unclassified”。原始代号始终保留在明细 brand_raw，不将 CH、BV 等代号直接当成最终品牌；相同代号可能在不同品类有不同含义。

### analysis_categories — 品类字典

id、code（唯一）、name_zh、name_en、时间戳。供应商表 Sheet 对应：包包、珠宝、鞋子、手表、衣服、帽子、眼镜、皮带、丝巾。

### analysis_suppliers — 供应商字典

id、identity（规范名称摘要，唯一）、name、mapping_rules JSONB、rules_import_id、时间戳。

mapping_rules 保存代号、品牌 ID、品类、来源 Sheet/行号、确认状态和原始证据。一家供应商可以对应多个品牌和品类。品牌代号与供应商的精确组合优先；多个候选不能唯一确定时保留待核查。商品文字仅作辅助证据，不能无依据补全品牌。

“品牌待确认”只阻止品牌绑定，已确认的供应关系仍可确定品类；“供应关系待确认”或“品牌/供应关系待确认”整行只存档，不参与品牌或品类映射，避免未核实的跨品类代号覆盖原有有效规则。

### analysis_imports — 技术批次表

id、source_type、filename、file_hash、signature、currency、price_basis、mode、periods JSONB、active_periods JSONB、is_active、status、summary JSONB、created_by、时间戳。

mode：initialize 首次全历史、current_month 日常当月、dictionary 供应商映射。
同一历史导入批次可以只剩 1–8 月有效，9 月由新批次接管。旧明细保留可追溯，不重复进入统计。

早期试验结构中的 analysis_supplier_rules 及订单关联预留列保留兼容，但本实现不读写这些关系，也不进行订单关联。新的匹配规则存放于上述字典。

## 页面功能

- 总成交金额、有效采购记录、客户数、每行平均金额。
- 页面默认查询北京时间当月；“上月／下月／本月”切换同步更新所选月的总览、排行和明细，仍可查询自定义日期或全部历史。
- 趋势按月扩展至筛选涉及的完整自然年（全年 12 个月），按日扩展至完整自然月（包含月末全部日期）。趋势范围在图表上显示；概览、排行和明细继续使用原始日期，品牌等条件仍统一生效。无数据的月份也保留坐标。
- 品牌/品类排行与占比、价格区间及采购方式分布。
- 品牌 × 品类、品类 × 价格区间、首复购 × 品牌、首复购 × 品类矩阵。
- 成交明细、客户汇总及客户下钻、全部来源、数据缺失、分类待核查。
- 原始行和匹配依据查看、双语 CSV、上传更新和批次记录。
- 列表/客户/批次均由接口分页，默认 20 条，可选 50/100，翻页显示区域加载蒙层；不会先取全部明细再浏览器分页。
- 导出忽略页码，使用当前筛选与语言，共 25 列；列表显示与 CSV 由同一后端字段定义生成。导出按月份固定版本，每块读取 500 行，Excel 公式前缀转义。

## 日常更新

1. 打开 Analysis → 上传更新，数据来源选“采购集成表”。
2. 上传含当前月份完整数据的 XLSX，默认“只更新当月 YYYY-MM”。当前月按北京时间计算，不允许任意覆盖历史月。
3. 完整解析、校验及私有备份保存成功后，事务内切换该月份快照。新表不再包含的当月旧行不再参与统计；其他月份行 ID、金额与数据保持不变。
4. 相同文件和月份再次上传直接复用，不追加。缺少当月 Sheet、重复月份 Sheet、空月份表、缺关键表头或解析失败，保留原数据。
5. 供应商表更新时选择“供应商表”，读取全部品类 Sheet，更新字典并重新匹配有效采购历史的 ID/名称快照。原采购原文及金额保持不变，待确认品牌仍不强制分类。

页面上传不会自动从金山文档拉取更新，也没有创建定时任务。请上传从在线文档导出的最新副本。

目前单文件限制 256 MiB，读取 XLSX 单元格和公式缓存，不加载图片。优先去掉图片；本次实际采购文件约 146.6 MiB，已可上传。若后续仍超限，应按月份导出当月 Sheet，不必携带全部历史和图片。

## 私有文件与部署

成功来源副本：storage/app/private/analysis/imports/<SHA256>.xlsx。它们不放在 public/storage，也不提供公开下载 URL。迁移服务器时同时迁移数据库和该私有目录；目录归 PHP 运行用户所有（本地容器 www-data），权限保持私有。

CLI 初始化应与 PHP 服务使用同一用户，避免 root 创建 0700 目录后 Web 上传无法访问：

~~~sh
docker exec -u www-data saveb-api-app php artisan analysis:import suppliers /path/suppliers.xlsx
docker exec -u www-data saveb-api-app php artisan analysis:import procurement /path/procurement.xlsx --mode=initialize
~~~

具体参数以 php artisan help analysis:import 为准。已经有历史数据后禁止再次初始化，日常用页面上传当月。

docker/business-uploads.ini：upload_max_filesize=256M、post_max_size=258M、memory_limit=512M、max_execution_time=300。nginx.conf：client_max_body_size=260m。生产应用相同限制，并确保反向代理允许上传及处理时间。不要用公开目录解决权限问题。

## API 与权限

统一 Bearer 认证。菜单 business.analysis；只读 business.analysis.list；导入 business.analysis.import；导出 business.analysis.export，现有 AnalysisMenuSeeder 为专用增量种子。

| 方法及路径（前缀 /api/workbench/analysis） | 输入 | 返回 data |
| --- | --- | --- |
| GET /options | locale | 字典、列定义、有效月份、当前月、初始化资格 |
| GET /report | 公共筛选、grain=day/month | summary、trend、distributions、crosses、currency |
| GET /rows | 公共筛选、scope、quality、page、per_page、locale | list、total、page、per_page、last_page |
| GET /customers | 公共筛选、page、per_page | 客户金额、首购日、复购金额及分页 |
| GET /rows/{id}/evidence | 当前行 ID | 原始单元格、公式、来源和匹配依据 |
| GET /imports | page、per_page | 批次、有效月份、操作者名称及分页 |
| POST /imports | multipart file、source_type、mode | id、reused、periods、summary |
| GET /export | 公共筛选、scope、quality、locale | UTF-8 BOM CSV，全部筛选记录 |

公共筛选：startDate/endDate（YYYY-MM-DD，含边界）、brand_id、category_id、supplier_id、source_period（YYYY-MM）、customer_key、customer_type、purchase_method、price_band、keyword。quality 支持 missing/classification/needs_review；默认列表 scope=eligible，quality 核查包含无效原始行。

详细每项出入参、枚举、错误和示例见可预览 API 文档。错误包括 401 未登录、403 无权限、404 行不存在、422 文件/参数不合要求。上传失败不切换有效版本。

## 本次数据核对（2026-09-10）

### 2026-09-11 供应商表补充及重新匹配

核对桌面采购集成表 2026.01–2026.09 的 34,094 行，以及供应商表全部 9 个品类 Sheet。原供应商文件已备份并写回，新增 357 条代号与供应商关系记录（含待确认线索），保留原优选顺序。无新增可确认关系的帽子、皮带、丝巾 Sheet 保留原关系并记录核对日期。采购文件未修改，数据库仅更新字典和历史明细的分类快照，供应商导入批次为 5。

| 核对范围 | 项目 | 更新前 | 更新后 |
| --- | --- | ---: | ---: |
| 全部原始行 | 品类未分类 | 8,858 | 7,366 |
| 全部原始行 | 品牌未分类 | 9,622 | 9,630 |
| 已采购且价格有效 | 品类未分类 | 3,637 | 2,344 |
| 已采购且价格有效 | 品牌未分类 | 4,205 | 4,202 |

所有行 ID、来源月份/行号/摘要、采购原文、成交金额、采购日期及首复购标记逐行一致；有效成交仍为 24,851 行，合计 CNY 16,721,994.73。9 月页面金额仍为 CNY 909,236.10，品类待确认从 297 减至 248（该提示包含非成交原始行）。

未分类没有全部消除：原始行中仍有 4,661 条缺供应商的品类未分类记录，以及 2,270 条跨品类歧义记录。新增关系中，原表同一代号存在其他品牌或其他品类定义的，不据此猜测品牌；关系本身未确认的仅存档。少量原先借用其他品类品牌定义的记录重新转为未匹配，因此全部原始行的品牌未分类数不一定下降。

备份及逐行核验文件位于 `storage/app/private/analysis/enrichment-20260911/`，包括原供应商文件、Analysis 表的 PostgreSQL 自定义格式备份、原文件摘要、更新前后明细及核验报告。分类回归通过 13 个测试、196 项断言；页面刷新正常且无浏览器错误。

使用原始 Excel 独立只读解析逐月核对，金额均一致：

| 来源月份 | 保存行数 | 有效成交行数 | CNY 金额 |
| --- | ---: | ---: | ---: |
| 2026-01 | 3,470 | 2,301 | 1,906,574.65 |
| 2026-02 | 2,487 | 1,889 | 1,406,738.30 |
| 2026-03 | 4,456 | 4,217 | 2,698,618.23 |
| 2026-04 | 4,551 | 394 | 266,324.00 |
| 2026-05 | 5,103 | 4,476 | 2,945,987.30 |
| 2026-06 | 4,702 | 3,948 | 2,517,859.90 |
| 2026-07 | 3,961 | 3,322 | 2,169,059.10 |
| 2026-08 | 3,847 | 3,023 | 1,901,597.15 |
| 2026-09 | 1,517 | 1,281 | 909,236.10 |
| 合计 | 34,094 | 24,851 | 16,721,994.73 |

供应商表 9 个 Sheet 共提取 589 条定义/关系（不是唯一品牌或供应商数）。全部源行中：价格缺失或无效 7,371，缺采购日期 4,107，缺客户名 517，品牌待核查 9,622，品类待核查 8,858，各质量项可能重叠。原始数据均保留；4 月有大量未采购/未完成行，不能按总行数计算成交。

无有效采购日期但有效成交金额 CNY 1,200.00，只纳入无日期限制总览。原表含 2026-09-11 的日期，本次保留来源值，不擅自删除未来日期。

## 验证命令

~~~sh
docker exec saveb-api-app php -d memory_limit=512M vendor/bin/phpunit -c phpunit-analysis.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-rbac.xml
docker exec saveb-api-app php scripts/check-method-docs.php
docker exec saveb-api-app php scripts/generate-api-docs.php
docker exec saveb-api-app php scripts/check-api-docs.php
~~~

Analysis 与 RBAC 测试在随机 rbac_test_* schema 中执行，不重建或清空业务库。Analysis 测试不创建订单/Invoice 表，以验证统计没有依赖这些数据源。
