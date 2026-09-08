# Saveb ERP 数据库表结构与字段字典

> 基线日期：2026-09-04  
> 数据库：PostgreSQL，`public` schema  
> 范围：正式环境实时元数据中的 46 张 BASE TABLE  
> 证据来源：只读查询 `information_schema` / `pg_catalog`、已应用 Knex migration 清单、现行迁移约束。未读取或展示任何正式业务记录、凭据、Cookie、Token 或客户明细。

## 1. 阅读说明

- 数据库当前没有表注释或字段注释；下文“说明”是依据字段名、外键、唯一约束、CHECK 约束和现行代码所作的工程释义，不应替代财务、合规或业务口径文件。
- `PK`=主键，`FK`=外键，`UQ`=唯一约束，`NN`=不可为空；未标 `NN` 的字段允许为空。
- `timestamptz` 为带时区时间；`jsonb` 为结构化扩展/快照数据。业务查询应优先使用关系型字段，不应长期依赖 `raw` 或兼容快照作为唯一事实源。
- 系统处于“历史 bigint 标识 + 新域 UUID 标识”并存阶段。跨新域关联优先使用 `entity_uuid`/UUID 外键，历史兼容接口仍可能使用 bigint `id`。
- 状态枚举主要由 CHECK 约束实现；当前没有 PostgreSQL enum 类型。

## 2. 领域分组总览

| 领域 | 表 |
|---|---|
| 订单与统计 | `orders`, `order_items`, `daily_stats`, `exchange_rates` |
| Pending 完成与绩效投影 | `order_user_overrides`, `order_staff_allocations`, `pending_completion_operations`, `order_status_observations`, `order_staff_performance_projection` |
| Invoice | `invoice_orders`, `invoice_items`, `invoice_adjustments`, `invoice_staff_allocations`, `invoice_operation_logs` |
| 采购、仓库与物流 | `procurement_tasks`, `purchase_tasks`, `purchase_task_items`, `warehouse_records`, `warehouse_receipts`, `warehouse_shipments`, `shipment_tracking_events`, `procurement_removed_orders` |
| PayPal | `paypal_accounts`, `paypal_balance_entries`, `paypal_reviews`, `paypal_withdrawals` |
| Influencer 与站点归类 | `influencers`, `influencer_domains`, `influencer_order_links`, `site_classification_reclassifications` |
| 用户、权限、审计与工作流 | `users`, `roles`, `permissions`, `user_roles`, `role_permissions`, `audit_logs`, `workflow_events`, `operation_cases`, `idempotency_keys`, `background_jobs`, `attachments` |
| 兼容、导入与运行状态 | `legacy_dashboard_days`, `legacy_import_items`, `system_state`, `knex_migrations`, `knex_migrations_lock` |

## 3. 订单与统计

### 3.1 `orders`

正式订单主表，保存订单身份、来源、金额、状态、归类及人员归属。`order_id`、`entity_uuid`、`visible_order_id` 均唯一。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 历史内部主键 |
| `order_id` | text NN | UQ | ERP 订单稳定标识 |
| `paypal_order_id` | text | — | PayPal 侧订单标识 |
| `order_time` | timestamptz | — | 订单业务时间 |
| `customer_name` | text | — | 客户姓名；敏感字段 |
| `source_site` | text | — | 订单来源站点/域名 |
| `classification` | text | — | 订单业务归类 |
| `influencer_name` | text | — | 归属 Influencer 名称快照 |
| `receiving_paypal` | text | — | 收款 PayPal 账号；敏感字段 |
| `amount_original` | numeric(14,2) | — | 原币金额 |
| `currency` | text | — | 原币币种 |
| `amount_usd` | numeric(14,2) | — | 折算美元金额 |
| `items_count` | integer | 默认 1 | 商品件数 |
| `product_name` | text | — | 历史兼容商品名称 |
| `order_status` | text | — | 订单当前状态 |
| `staff_code` | text | — | 历史/主负责人编码 |
| `raw` | jsonb NN | — | 上游原始订单快照；兼容/追溯用途 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `updated_at` | timestamptz NN | 当前时间 | 最近更新时间 |
| `client_order_id` | text | — | 客户侧订单标识 |
| `entity_uuid` | uuid NN | UQ | 新域稳定 UUID |
| `visible_order_id` | bigint | UQ；序列 | 面向界面/人员的顺序号 |
| `version` | integer NN | 默认 1 | 乐观锁版本 |

### 3.2 `order_items`

订单商品明细；一张订单可有多条商品记录，删除订单时级联删除。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 商品明细标识 |
| `order_uuid` | uuid NN | FK→`orders.entity_uuid` | 所属订单 |
| `sku` | text | — | 商品 SKU |
| `product_name` | text NN | — | 商品名称 |
| `quantity` | integer NN | 默认 1 | 数量 |
| `unit_price` | numeric(14,2) | — | 单价 |
| `currency` | text | — | 单价币种 |
| `metadata` | jsonb NN | 默认 `{}` | 商品扩展属性 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |

### 3.3 `daily_stats`

按业务日期、渠道、员工维度汇总订单、件数和美元金额；三字段组合唯一。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 汇总记录主键 |
| `stat_date` | date NN | UQ 组成 | 业务统计日 |
| `channel` | text NN | UQ 组成 | 渠道维度 |
| `staff_code` | text NN | UQ 组成；默认空串 | 员工维度；空串表示汇总口径 |
| `orders_count` | numeric(18,10) NN | 默认 0 | 按份额计算的订单数 |
| `items_count` | numeric(18,10) NN | 默认 0 | 按份额计算的件数 |
| `usd_amount` | numeric(18,10) NN | 默认 0 | 按份额计算的美元金额 |
| `updated_at` | timestamptz NN | 当前时间 | 汇总刷新时间 |

### 3.4 `exchange_rates`

按币种和生效日期维护兑美元汇率。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 汇率记录主键 |
| `currency` | text NN | UQ 组成 | 币种代码 |
| `rate_to_usd` | numeric(18,8) NN | — | 兑美元汇率 |
| `effective_date` | date NN | UQ 组成 | 生效日期 |

## 4. Pending 完成与绩效投影

### 4.1 `order_user_overrides`

保存用户对订单状态及主负责人的显式覆盖。身份键可为 client/order/paypal；当前状态覆盖仅允许 `completed`。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 覆盖记录标识 |
| `order_key` | text NN | UQ 组成 | 被覆盖订单的稳定身份值 |
| `order_key_type` | text NN | UQ 组成；CHECK | 身份类型：`client`/`order`/`paypal` |
| `order_uuid` | uuid | FK→`orders.entity_uuid` | 解析后的正式订单；删除订单时置空 |
| `source_status` | text NN | — | 覆盖前状态 |
| `status_override` | text NN | CHECK=`completed` | 用户指定状态 |
| `primary_staff_code` | text NN | — | 主负责人编码 |
| `version` | integer NN | 默认 1；>0 | 乐观锁版本 |
| `updated_by_user_uuid` | uuid NN | FK→`users.entity_uuid` | 最后修改用户 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `updated_at` | timestamptz NN | 当前时间 | 更新时间 |

### 4.2 `order_staff_allocations`

保存一次订单覆盖中的人员参与角色和绩效份额；同一覆盖下员工唯一，份额大于 0 且不超过 1。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 分配记录标识 |
| `order_override_id` | uuid NN | FK→`order_user_overrides.id` | 所属覆盖记录；级联删除 |
| `staff_code` | text NN | UQ 组成 | 员工编码 |
| `participant_role` | text NN | CHECK | `primary` 或 `collaborator` |
| `share_ratio` | numeric(12,10) NN | CHECK `(0,1]` | 绩效分摊比例 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |

### 4.3 `pending_completion_operations`

Pending→Completed 操作的不可变业务结果记录；订单、操作 UUID、身份键均防重复。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `operation_uuid` | uuid NN | PK | 操作稳定标识 |
| `identity_type` | text NN | UQ 组成；CHECK | `client`/`order`/`paypal` |
| `identity_key` | text NN | UQ 组成 | 订单稳定身份值 |
| `order_uuid` | uuid NN | UQ；FK→`orders.entity_uuid` | 目标正式订单 |
| `business_date` | date NN | — | 归属业务日期 |
| `source_status` | text NN | CHECK=`pending` | 操作前状态 |
| `target_status` | text NN | CHECK=`completed` | 操作后状态 |
| `target_classification` | text NN | CHECK=`payment_link` | 目标归类 |
| `result` | jsonb NN | — | 操作结果快照 |
| `completed_at` | timestamptz NN | — | 完成时间 |
| `created_at` | timestamptz NN | 当前时间 | 记录创建时间 |

### 4.4 `order_status_observations`

记录上游订单状态观测及其与完成操作的绑定，用于历史追溯和幂等投影。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 观测记录主键 |
| `order_uuid` | uuid NN | FK→`orders.entity_uuid` | 正式订单 |
| `identity_type` | text NN | — | 观测使用的身份类型 |
| `identity_key` | text NN | — | 观测使用的身份值 |
| `source_system` | text NN | 默认 `saveb_erp` | 来源系统 |
| `order_source_stable_key` | text NN | — | 来源系统稳定订单键 |
| `status` | text NN | — | 上游原始状态 |
| `normalized_status` | text NN | — | 归一化状态 |
| `classification` | text | — | 观测时归类 |
| `source_business_time` | timestamptz | — | 上游业务时间 |
| `observed_at` | timestamptz NN | — | 系统观测时间 |
| `source` | text NN | — | 观测来源/触发路径 |
| `operation_uuid` | uuid NN | UQ；FK→`pending_completion_operations.operation_uuid` | 对应完成操作 |
| `bounded_projection` | jsonb NN | 默认 `{}` | 有界投影结果 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |

### 4.5 `order_staff_performance_projection`

把一次完成操作按员工和份额固化为订单数、件数、金额与佣金投影；同一操作下员工唯一。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 投影记录主键 |
| `operation_uuid` | uuid NN | UQ 组成；FK→`pending_completion_operations` | 来源操作 |
| `order_uuid` | uuid NN | FK→`orders.entity_uuid` | 来源订单 |
| `business_date` | date NN | — | 绩效归属日 |
| `staff_code` | text NN | UQ 组成 | 员工编码 |
| `share_ratio` | numeric(12,10) NN | CHECK `(0,1]` | 员工份额 |
| `orders_basis` | numeric(18,10) NN | — | 订单数投影基数 |
| `items_basis` | numeric(18,10) NN | — | 件数投影基数 |
| `amount_usd_basis` | numeric(18,10) NN | — | 美元金额投影基数 |
| `commission_percent` | numeric(12,6) | CHECK `>=0` | 佣金比例 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |

## 5. Invoice

### 5.1 `invoice_orders`

Invoice 主表，保存发票/订单身份、客户信息、状态、折扣、金额及截图关联。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 历史内部主键 |
| `legacy_id` | text | UQ | 旧系统记录标识 |
| `order_number` | text NN | — | Invoice 订单号 |
| `invoice_date` | date NN | — | Invoice 日期 |
| `customer_full_name` | text | — | 客户全名；敏感字段 |
| `customer_email` | text | — | 客户邮箱；敏感字段 |
| `phone_number` | text | — | 电话；敏感字段 |
| `country` | text | — | 国家/地区 |
| `country_source` | text | — | 国家识别来源 |
| `address` | text | — | 地址；敏感字段 |
| `invoice_link` | text | — | Invoice 链接 |
| `invoice_status` | text NN | — | Invoice 状态 |
| `expedited_shipping` | boolean | 默认 false | 是否加急运输 |
| `fixed_discount` | numeric(14,2) | — | 固定金额折扣 |
| `percentage_discount` | numeric(14,2) | — | 百分比折扣 |
| `gift_box` | text | 默认 `Has` | 礼盒/包装状态 |
| `amount_usd` | numeric(14,2) NN | — | Invoice 美元金额 |
| `recipient_paypal` | text | — | 收款 PayPal；敏感字段 |
| `created_by` | bigint | FK→`users.id` | 创建用户（历史 bigint） |
| `raw` | jsonb | — | OCR/导入原始快照 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `updated_at` | timestamptz NN | 当前时间 | 更新时间 |
| `order_date` | date | — | Date Ordered/订单业务日期 |
| `invoice_screenshot_attachment_id` | bigint | FK→`attachments.id` | Invoice 截图附件 |
| `entity_uuid` | uuid NN | UQ | 新域稳定 UUID |
| `version` | integer NN | 默认 1 | 乐观锁版本 |

### 5.2 `invoice_items`

Invoice 商品明细；随 Invoice 级联删除。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 历史内部主键 |
| `invoice_id` | bigint NN | FK→`invoice_orders.id` | 所属 Invoice |
| `product_name` | text | — | 商品名称 |
| `description` | text | — | 商品说明 |
| `quantity` | integer | 默认 1 | 数量 |
| `price` | numeric(14,2) | — | 单价/行金额 |
| `notes` | text | — | 备注 |
| `image_attachment_id` | bigint | FK→`attachments.id` | 商品图片附件 |
| `entity_uuid` | uuid NN | UQ | 新域稳定 UUID |

### 5.3 `invoice_adjustments`

Invoice 折扣、抵扣、运费、无盒等结构化调整项；随 Invoice 级联删除。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 调整项标识 |
| `invoice_uuid` | uuid NN | FK→`invoice_orders.entity_uuid` | 所属 Invoice |
| `adjustment_type` | text NN | CHECK | `discount`/`credit`/`shipping`/`no_box`/`other` |
| `amount` | numeric(14,2) | — | 固定调整金额 |
| `percentage` | numeric(14,2) | — | 百分比调整 |
| `reason` | text | — | 调整原因 |
| `created_by_user_uuid` | uuid | FK→`users.entity_uuid` | 创建用户 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |

### 5.4 `invoice_staff_allocations`

Invoice 人员与佣金/份额分配。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 分配记录主键 |
| `invoice_id` | bigint NN | FK→`invoice_orders.id` | 所属 Invoice；级联删除 |
| `staff_code` | text NN | — | 员工编码 |
| `commission_percent` | numeric(5,2) | 默认 0 | 佣金百分比 |
| `share_ratio` | numeric(6,4) NN | 默认 1 | 分摊比例 |

### 5.5 `invoice_operation_logs`

Invoice 变更审计日志，记录动作、前后快照、操作者和请求标识。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 日志标识 |
| `invoice_uuid` | uuid NN | FK→`invoice_orders.entity_uuid` | Invoice；级联删除 |
| `action` | text NN | — | 操作动作 |
| `before` | jsonb | — | 变更前快照 |
| `after` | jsonb | — | 变更后快照 |
| `actor_user_uuid` | uuid NN | FK→`users.entity_uuid` | 操作者 |
| `request_id` | text | — | 请求追踪标识 |
| `source` | text NN | 默认 `api` | 操作来源 |
| `created_at` | timestamptz NN | 当前时间 | 操作时间 |

## 6. 采购、仓库与物流

### 6.1 `procurement_tasks`

历史采购任务表，兼容旧流程与旧接口；新流程主表为 `purchase_tasks`。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 历史采购任务主键 |
| `order_pk` | bigint | FK→`orders.id` | 关联订单历史主键 |
| `order_id` | text | — | 订单标识快照 |
| `purchase_status` | text NN | 默认 `pending_purchase`；CHECK | 采购流程状态 |
| `supplier` | text | — | 供应商 |
| `cost` | numeric(14,2) | — | 采购成本 |
| `eta` | date | — | 预计到达日 |
| `tracking_no` | text | — | 物流单号 |
| `notes` | text | — | 采购备注 |
| `created_by` | bigint | FK→`users.id` | 创建用户（历史 bigint） |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `updated_at` | timestamptz NN | 当前时间 | 更新时间 |
| `legacy_id` | text | UQ | 旧系统稳定标识 |
| `raw` | jsonb | — | 旧系统原始快照 |
| `entity_uuid` | uuid NN | UQ | 新域稳定 UUID |
| `version` | integer NN | 默认 1 | 乐观锁版本 |

状态约束：`pending_purchase`, `supplier_shipping_pending`, `warehouse_arrived`, `exchange_in_progress`, `return_in_progress`, `customer_confirm_pending`, `shipped`。

### 6.2 `purchase_tasks`

新采购域任务主表，支持订单采购、补发、换货及其他任务。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 采购任务标识 |
| `task_number` | bigint NN | UQ；序列 | 可见任务编号 |
| `order_uuid` | uuid | FK→`orders.entity_uuid` | 关联正式订单 |
| `legacy_procurement_id` | bigint | FK→`procurement_tasks.id` | 对应历史采购任务 |
| `task_type` | text NN | CHECK | `order_purchase`/`replacement`/`exchange`/`other` |
| `source` | text NN | CHECK | `system` 或 `manual`；system 必须有关联订单 |
| `status` | text NN | 默认 `pending_purchase`；CHECK | 新采购流程状态 |
| `supplier` | text | — | 供应商 |
| `purchase_cost` | numeric(14,2) | — | 采购成本 |
| `eta` | date | — | 预计到达日 |
| `tracking_number` | text | — | 物流单号 |
| `notes` | text | — | 备注 |
| `version` | integer NN | 默认 1 | 乐观锁版本 |
| `created_by_user_uuid` | uuid NN | FK→`users.entity_uuid` | 创建用户 |
| `updated_by_user_uuid` | uuid NN | FK→`users.entity_uuid` | 最后修改用户 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `updated_at` | timestamptz NN | 当前时间 | 更新时间 |

状态约束：`pending_purchase`, `waiting_supplier_shipment`, `arrived_warehouse`, `inspection`, `shipped`, `exchange_pending`, `exchange_in_progress`, `return_pending`, `return_in_progress`, `cancelled`。

### 6.3 `purchase_task_items`

新采购任务的商品明细；随采购任务级联删除。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 明细标识 |
| `purchase_task_id` | uuid NN | FK→`purchase_tasks.id` | 所属采购任务 |
| `order_item_id` | uuid | FK→`order_items.id` | 对应订单商品 |
| `sku` | text | — | SKU |
| `product_name` | text NN | — | 商品名称 |
| `quantity` | integer NN | 默认 1 | 数量 |
| `notes` | text | — | 备注 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |

### 6.4 `warehouse_records`

历史仓库履约记录；每个历史采购任务最多一条。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 仓库记录主键 |
| `procurement_task_id` | bigint NN | UQ；FK→`procurement_tasks.id` | 历史采购任务；级联删除 |
| `fulfillment_status` | text | — | 履约状态 |
| `items` | jsonb NN | 默认 `[]` | 历史商品明细快照 |
| `history` | jsonb NN | 默认 `[]` | 历史状态轨迹 |
| `updated_by` | bigint | FK→`users.id` | 最后修改用户 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `updated_at` | timestamptz NN | 当前时间 | 更新时间 |

### 6.5 `warehouse_receipts`

新仓库收货/质检记录。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 收货记录标识 |
| `purchase_task_id` | uuid NN | FK→`purchase_tasks.id` | 采购任务 |
| `receipt_number` | text NN | UQ | 收货单号 |
| `inspection_status` | text NN | 默认 `pending`；CHECK | `pending`/`passed`/`failed`/`partial` |
| `received_items` | jsonb NN | 默认 `[]` | 实收商品结构化清单 |
| `received_by_user_uuid` | uuid NN | FK→`users.entity_uuid` | 收货用户 |
| `received_at` | timestamptz NN | 当前时间 | 收货时间 |

### 6.6 `warehouse_shipments`

新仓库发货记录。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 发货记录标识 |
| `purchase_task_id` | uuid NN | FK→`purchase_tasks.id` | 采购任务 |
| `shipment_number` | text NN | UQ | 发货单号 |
| `carrier` | text | — | 承运商 |
| `tracking_number` | text | — | 物流单号 |
| `shipped_items` | jsonb NN | 默认 `[]` | 发货商品结构化清单 |
| `shipped_by_user_uuid` | uuid NN | FK→`users.entity_uuid` | 发货用户 |
| `shipped_at` | timestamptz NN | 当前时间 | 发货时间 |

### 6.7 `shipment_tracking_events`

承运商物流事件；`provider + event_id` 唯一以防重复导入。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 事件主键 |
| `procurement_task_id` | bigint | FK→`procurement_tasks.id` | 历史采购任务；级联删除 |
| `provider` | text NN | UQ 组成 | 物流数据提供方 |
| `tracking_number` | text NN | — | 物流单号 |
| `status` | text | — | 物流主状态 |
| `substatus` | text | — | 物流子状态 |
| `event_id` | text | UQ 组成 | 提供方事件标识 |
| `raw` | jsonb NN | 默认 `{}` | 提供方原始事件快照 |
| `occurred_at` | timestamptz | — | 事件发生时间 |
| `created_at` | timestamptz NN | 当前时间 | 入库时间 |

### 6.8 `procurement_removed_orders`

从采购视图/流程移除的订单留痕，用于防止误恢复和支持审计。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 留痕主键 |
| `legacy_id` | text NN | UQ | 旧系统稳定标识 |
| `order_id` | text | — | ERP 订单标识 |
| `paypal_order_id` | text | — | PayPal 订单标识 |
| `raw` | jsonb NN | — | 移除时快照 |
| `removed_by` | bigint | FK→`users.id` | 执行移除用户 |
| `removed_at` | timestamptz NN | 当前时间 | 移除时间 |

## 7. PayPal

### 7.1 `paypal_accounts`

PayPal 账号主数据。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 账号主键 |
| `email` | text NN | UQ | PayPal 邮箱；敏感字段 |
| `account_name` | text | — | 账号显示名称 |
| `added_date` | date | — | 添加日期 |
| `active` | boolean NN | 默认 true | 是否启用 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `meta` | jsonb NN | 默认 `{}` | 扩展元数据 |

### 7.2 `paypal_balance_entries`

PayPal 余额录入流水；余额更新以新增记录形成历史轨迹。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 余额记录主键 |
| `account_id` | bigint NN | FK→`paypal_accounts.id` | PayPal 账号 |
| `balance` | numeric(14,2) NN | — | 当次记录余额 |
| `entered_by` | bigint | FK→`users.id` | 录入用户 |
| `created_at` | timestamptz NN | 当前时间 | 录入时间 |

### 7.3 `paypal_reviews`

PayPal 账号 Review 数量历史记录。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | Review 记录主键 |
| `account_id` | bigint NN | FK→`paypal_accounts.id` | PayPal 账号 |
| `review_count` | integer NN | — | 当次 Review 数量 |
| `entered_by` | bigint | FK→`users.id` | 录入用户 |
| `created_at` | timestamptz NN | 当前时间 | 录入时间 |

### 7.4 `paypal_withdrawals`

PayPal 提现记录。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 提现记录主键 |
| `account_id` | bigint NN | FK→`paypal_accounts.id` | PayPal 账号 |
| `amount` | numeric(14,2) NN | — | 提现金额 |
| `source` | text | — | 提现来源/备注 |
| `withdrawn_at` | date NN | — | 提现业务日期 |
| `created_by` | bigint | FK→`users.id` | 创建用户 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |

## 8. Influencer 与站点归类

### 8.1 `influencers`

Influencer 主数据。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | Influencer 标识 |
| `display_name` | text NN | — | 显示名称 |
| `status` | text NN | 默认 `active` | 当前状态 |
| `profile` | jsonb NN | 默认 `{}` | 扩展资料 |
| `version` | integer NN | 默认 1 | 乐观锁版本 |
| `created_by_user_uuid` | uuid | FK→`users.entity_uuid` | 创建用户 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `updated_at` | timestamptz NN | 当前时间 | 更新时间 |

### 8.2 `influencer_domains`

域名到 Influencer 的识别映射；域名唯一。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 映射主键 |
| `domain` | text NN | UQ | 来源域名 |
| `influencer_name` | text | — | Influencer 名称 |
| `confirmed` | boolean NN | 默认 false | 是否人工确认 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |

### 8.3 `influencer_order_links`

Influencer 与订单的多对多关联；同一 Influencer 与订单组合唯一。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 关联标识 |
| `influencer_id` | uuid NN | FK→`influencers.id` | Influencer |
| `order_uuid` | uuid NN | FK→`orders.entity_uuid` | 订单 |
| `source` | text NN | 默认 `manual` | 关联来源 |
| `created_by_user_uuid` | uuid | FK→`users.entity_uuid` | 创建用户 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |

### 8.4 `site_classification_reclassifications`

按发布批次记录站点归类重分类结果，支持回溯前后值；`release_id + order_id` 为复合主键。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `release_id` | text NN | PK 组成 | 发布/修复批次标识 |
| `order_id` | text NN | PK 组成 | ERP 订单标识 |
| `source_domain` | text NN | — | 来源域名 |
| `previous_classification` | text NN | — | 重分类前归类 |
| `previous_influencer_name` | text | — | 重分类前 Influencer |
| `target_classification` | text NN | — | 重分类后归类 |
| `target_influencer_name` | text | — | 重分类后 Influencer |
| `created_at` | timestamptz NN | 当前时间 | 重分类记录时间 |

## 9. 用户、权限、审计与工作流

### 9.1 `users`

用户主表；同时保留历史单角色字段 `role` 和新 RBAC 关系 `user_roles`。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 历史用户主键 |
| `username` | text NN | UQ | 登录用户名 |
| `password_hash` | text NN | — | 密码哈希；严禁导出或记录明文 |
| `display_name` | text NN | — | 显示名称 |
| `role` | text NN | CHECK | 历史单角色代码 |
| `staff_code` | text | — | 关联员工编码 |
| `active` | boolean NN | 默认 true | 是否启用 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `updated_at` | timestamptz NN | 当前时间 | 更新时间 |
| `must_change_password` | boolean NN | 默认 false | 下次登录是否强制改密 |
| `entity_uuid` | uuid NN | UQ | 新域稳定 UUID |

角色约束：`admin`, `finance`, `cs`, `customer_service`, `customer_service_client`, `purchasing`, `warehouse`, `influencer`, `operations`, `viewer`。

### 9.2 `roles`

RBAC 角色主数据。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 角色标识 |
| `code` | text NN | UQ | 角色代码 |
| `name` | text NN | — | 角色名称 |
| `department` | text NN | — | 所属部门 |
| `active` | boolean NN | 默认 true | 是否启用 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |

### 9.3 `permissions`

RBAC 权限主数据。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 权限标识 |
| `code` | text NN | UQ | 权限代码 |
| `name` | text NN | — | 权限名称 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |

### 9.4 `user_roles`

用户与角色多对多关系；`user_uuid + role_id` 为复合主键。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `user_uuid` | uuid NN | PK；FK→`users.entity_uuid` | 用户；级联删除 |
| `role_id` | uuid NN | PK；FK→`roles.id` | 角色；级联删除 |
| `granted_by_user_uuid` | uuid | FK→`users.entity_uuid` | 授权人 |
| `created_at` | timestamptz NN | 当前时间 | 授权时间 |

### 9.5 `role_permissions`

角色与权限多对多关系；两字段构成复合主键，任一主数据删除时级联删除。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `role_id` | uuid NN | PK；FK→`roles.id` | 角色 |
| `permission_id` | uuid NN | PK；FK→`permissions.id` | 权限 |

### 9.6 `audit_logs`

通用审计日志，记录用户、动作、实体、前后快照及请求 IP。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 日志主键 |
| `user_id` | bigint | FK→`users.id` | 操作用户 |
| `action` | text NN | — | 操作动作 |
| `entity_type` | text NN | — | 实体类型 |
| `entity_id` | text | — | 实体标识 |
| `before` | jsonb | — | 变更前快照 |
| `after` | jsonb | — | 变更后快照 |
| `ip` | text | — | 请求 IP；安全敏感字段 |
| `created_at` | timestamptz NN | 当前时间 | 操作时间 |

### 9.7 `workflow_events`

新域统一工作流事件表，记录实体状态迁移及操作者。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 事件标识 |
| `entity_type` | text NN | — | 实体类型 |
| `entity_uuid` | uuid NN | — | 实体 UUID |
| `event_type` | text NN | — | 事件类型 |
| `from_state` | text | — | 迁移前状态 |
| `to_state` | text | — | 迁移后状态 |
| `actor_user_uuid` | uuid NN | FK→`users.entity_uuid` | 操作者 |
| `request_id` | text | — | 请求追踪标识 |
| `source` | text NN | 默认 `api` | 事件来源 |
| `before` | jsonb | — | 事件前快照 |
| `after` | jsonb | — | 事件后快照 |
| `created_at` | timestamptz NN | 当前时间 | 事件时间 |

### 9.8 `operation_cases`

异常、复核或人工处理事项的统一 Case 表。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | Case 标识 |
| `case_type` | text NN | — | Case 类型 |
| `status` | text NN | 默认 `open` | 当前状态 |
| `entity_type` | text | — | 关联实体类型 |
| `entity_uuid` | uuid | — | 关联实体 UUID |
| `summary` | text NN | — | 摘要 |
| `details` | jsonb NN | 默认 `{}` | 结构化详情 |
| `version` | integer NN | 默认 1 | 乐观锁版本 |
| `owner_user_uuid` | uuid | FK→`users.entity_uuid` | 当前负责人 |
| `created_by_user_uuid` | uuid NN | FK→`users.entity_uuid` | 创建用户 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `updated_at` | timestamptz NN | 当前时间 | 更新时间 |

### 9.9 `idempotency_keys`

写操作幂等控制表；同一用户、scope 和幂等键组合唯一。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 幂等记录标识 |
| `actor_user_uuid` | uuid NN | FK→`users.entity_uuid` | 发起用户 |
| `scope` | text NN | UQ 组成 | 幂等作用域 |
| `idempotency_key` | text NN | UQ 组成 | 客户端幂等键 |
| `request_hash` | text NN | — | 请求内容摘要 |
| `state` | text NN | 默认 `processing`；CHECK | `processing`/`completed` |
| `response_status` | integer | — | 已完成请求的 HTTP 状态 |
| `response_body` | jsonb | — | 已完成请求的响应快照 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `expires_at` | timestamptz NN | 当前时间+24h | 过期时间 |

### 9.10 `background_jobs`

异步任务主表，覆盖 OCR、批量导出和报表刷新，并记录进度、结果、错误、重试和到期时间。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | uuid NN | PK | 异步任务标识 |
| `job_type` | text NN | CHECK | `invoice_ocr`/`batch_export`/`report_refresh` |
| `status` | text NN | 默认 `queued`；CHECK | `queued`/`processing`/`succeeded`/`failed`/`cancelled` |
| `progress_percent` | integer NN | 默认 0；CHECK 0–100 | 进度百分比 |
| `queue_job_id` | text | — | 队列系统任务标识 |
| `input_attachment_uuid` | uuid | FK→`attachments.entity_uuid` | 输入附件 |
| `input_sha256` | text | — | 输入文件 SHA-256 |
| `engine` | text | — | 处理引擎 |
| `model_version` | text | — | 模型/算法版本 |
| `input` | jsonb NN | 默认 `{}` | 任务输入参数 |
| `result` | jsonb | — | 任务结果 |
| `error_code` | text | — | 失败错误码 |
| `error_message` | text | — | 脱敏错误信息 |
| `created_by_user_uuid` | uuid NN | FK→`users.entity_uuid` | 创建用户 |
| `cancel_requested` | boolean NN | 默认 false | 是否请求取消 |
| `retry_count` | integer NN | 默认 0 | 重试次数 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `updated_at` | timestamptz NN | 当前时间 | 更新时间 |
| `started_at` | timestamptz | — | 开始执行时间 |
| `finished_at` | timestamptz | — | 结束时间 |
| `expires_at` | timestamptz NN | 当前时间+24h | 任务/结果到期时间 |
| `input_fingerprint` | text | — | 输入去重指纹 |

### 9.11 `attachments`

统一附件元数据表；只保存文件位置、类型、大小和哈希，不在数据库中保存文件正文。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 历史附件主键 |
| `entity_type` | text NN | — | 所属实体类型 |
| `entity_id` | bigint | — | 历史实体主键 |
| `file_path` | text NN | — | 受控存储路径；安全敏感元数据 |
| `mime` | text | — | MIME 类型 |
| `size_bytes` | bigint | — | 文件字节数 |
| `sha256` | text NN | — | 文件完整性摘要 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `entity_uuid` | uuid NN | UQ | 新域稳定附件 UUID |

## 10. 兼容、导入与运行状态

### 10.1 `legacy_dashboard_days`

旧版 Dashboard 按日快照兼容表；`day` 为主键，用来源哈希和大小保证可追溯。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `day` | date NN | PK | 快照业务日 |
| `payload` | jsonb NN | — | 旧版 Dashboard 日数据 |
| `source_sha256` | varchar(64) NN | — | 来源文件 SHA-256 |
| `source_size_bytes` | bigint NN | — | 来源文件大小 |
| `snapshot_cutoff_asia_shanghai` | text NN | — | Asia/Shanghai 快照截止时间描述 |
| `created_at` | timestamptz NN | 当前时间 | 创建时间 |
| `updated_at` | timestamptz NN | 当前时间 | 更新时间 |

### 10.2 `legacy_import_items`

旧数据导入幂等清单；每个 `source_key` 仅允许导入一次。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | bigint NN | PK；序列 | 导入记录主键 |
| `source_key` | text NN | UQ | 来源数据稳定键 |
| `source_sha256` | text NN | — | 来源内容摘要 |
| `entity_type` | text NN | — | 导入目标实体类型 |
| `entity_id` | text | — | 导入目标实体标识 |
| `imported_at` | timestamptz NN | 当前时间 | 导入时间 |

### 10.3 `system_state`

系统级键值状态表，用于保存同步水位、刷新状态等小型运行状态；不得存放凭据。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `key` | text NN | PK | 状态键 |
| `value` | jsonb NN | 默认 `{}` | 状态值 |
| `updated_at` | timestamptz NN | 当前时间 | 最近更新时间 |

### 10.4 `knex_migrations`

Knex 自动维护的迁移执行清单。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `id` | integer NN | PK；序列 | 迁移记录主键 |
| `name` | varchar(255) | — | 迁移文件名 |
| `batch` | integer | — | 执行批次 |
| `migration_time` | timestamptz | — | 执行时间 |

### 10.5 `knex_migrations_lock`

Knex 自动维护的迁移锁，避免并发执行迁移。

| 字段 | 类型/可空 | 键/默认 | 说明 |
|---|---|---|---|
| `index` | integer NN | PK；序列 | 锁记录主键 |
| `is_locked` | integer | — | 锁状态（Knex 内部语义） |

## 11. 核心关系摘要

```text
users ──< user_roles >── roles ──< role_permissions >── permissions
  │             │
  ├──< audit_logs / workflow_events / operation_cases / background_jobs
  │
orders ──< order_items
  ├──< order_user_overrides ──< order_staff_allocations
  ├── pending_completion_operations ──< order_status_observations
  │                                  └──< order_staff_performance_projection
  ├──< procurement_tasks ── warehouse_records / shipment_tracking_events
  └──< purchase_tasks ──< purchase_task_items
                     ├──< warehouse_receipts
                     └──< warehouse_shipments

invoice_orders ──< invoice_items / invoice_adjustments
               ├──< invoice_staff_allocations
               └──< invoice_operation_logs

paypal_accounts ──< paypal_balance_entries / paypal_reviews / paypal_withdrawals
influencers ──< influencer_order_links >── orders
attachments ── invoice_orders / invoice_items / background_jobs
```

## 12. 已应用迁移基线

实时迁移清单为：

1. `202607130001_initial_schema.js`
2. `202607130002_current_production_extensions.js`
3. `202607130003_order_identity.js`
4. `202607130004_invoice_identity.js`
5. `202607130005_invoice_discount_precision.js`
6. `202607140001_transactional_domain_foundation.js`
7. `202607140002_release_blockers.js`
8. `202607140003_legacy_dashboard_compatibility.js`
9. `202607250001_pending_order_completion_overrides.js`
10. `202608120002_pending_completion_global_projection.js`
11. `202608120001_customer_service_client_role.js`
12. `202608120003_unmatched_site_grouping.js`

## 13. 维护建议

1. 后续 migration 应同步增加 PostgreSQL `COMMENT ON TABLE/COLUMN`，让业务释义成为数据库可查询元数据。
2. 新功能优先使用 UUID 新域表；对 bigint 历史表的兼容关系应显式记录，不再新增隐式文本关联。
3. `raw`、`payload`、`metadata` 等 JSONB 字段只承载上游快照或低频扩展；用于报表、对账、权限或状态机的关键属性应提升为受约束字段。
4. 客户姓名、邮箱、电话、地址、PayPal 邮箱、IP、文件路径和密码哈希均按敏感数据处理；文档、日志、测试夹具和证据包不得包含正式值。
5. 状态字段新增值时，应在同一 migration 中更新 CHECK 约束、后端校验、前端字典、回归测试和本文档。

