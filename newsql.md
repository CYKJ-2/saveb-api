# Saveb ERP → API 数据迁移基线表结构（v3：原貌保留版）

> 设计原则（v3，本版本基于实操经验修正）：
> 1. **保留原貌**：除非该表已通过 `2026_09_04_180000_create_rbac.php` 重新建模为 RBAC 域，**所有其它业务表一律沿用 `saveb-erp-database-schema-data-dictionary-20260904.md` / `sql.md` 中的原始字段定义**（包括 UUID 主键、UUID 外键、原数值精度、原字符串类型）。不在本次迁移中做任何 bigint ↔ UUID 的跨主键体系转换。这一决定基于前两轮（v1/v2）迁移中暴露出的多个真实数据失败案例。
> 2. **加时间戳**：在原表结构基础上统一追加三个时间戳列 `created_at timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP`、`updated_at timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP`、`deleted_at timestamptz(6) NULL`，让 Laravel Eloquent `SoftDeletes` 与 `$timestamps` 一致行为得以支持。
> 3. **时间戳默认值升级**：原表已存在的 `created_at`/`updated_at` 列按 `timestamptz(6) DEFAULT CURRENT_TIMESTAMP` 处理；`deleted_at` 不设默认值。
> 4. **CHECK 约束**：沿用 `data-dictionary` 中已记录的 CHECK 枚举；data-dictionary 中未记录 CHECK 的表（如 `influencers.status` 的 data-dictionary 是单值 default `active`）按业务释义显式补齐。
> 5. **新增列注释**：所有字段配 `COMMENT ON TABLE/COLUMN`，便于数据库自描述。
> 6. **强依赖**：所有 FK 默认引用父表的稳定主键。UUID 主表的外键统一引用 `父表.entity_uuid`，bigint 主表的外键引用 `父表.id`，互不混用。

## 目录

1. [设计总则](#1-设计总则)
2. [成功落地的 RBAC 表（不动）](#2-成功落地的-rbac-表不动)
3. [订单与统计](#3-订单与统计)
4. [Pending 完成与绩效](#4-pending-完成与绩效)
5. [发票](#5-发票)
6. [PayPal](#6-paypal)
7. [Influencer 与站点](#7-influencer-与站点)
8. [工作流 / 审计 / 附件](#8-工作流--审计--附件)
9. [系统与兼容](#9-系统与兼容)
10. [迁移经验记录（v1/v2 → v3）](#10-迁移经验记录v1v2--v3)

---

## 1. 设计总则

### 1.1 与历史版本（v1/v2）相比的差异

| 维度 | v1（旧） | v2（已尝试） | **v3（本版）** |
|---|---|---|---|
| `order_items.id` | `uuid` | `bigint $table->id()` | **`uuid`**（保留 ERP 原貌） |
| `order_items.order_uuid` | `uuid` FK→`orders.entity_uuid` | `unsignedBigInteger('order_id')` | **`uuid`** FK→`orders.entity_uuid` |
| `order_user_overrides.id` | `uuid` | `bigint $table->id()` | **`uuid`** |
| `order_user_overrides.order_uuid` | `uuid` FK→`orders.entity_uuid` | `unsignedBigInteger('order_id')` | **`uuid`** |
| `invoice_operation_logs.id` | `uuid` | `bigint $table->id()` | **`uuid`** |
| `invoice_operation_logs.invoice_uuid` | `uuid` FK→`invoice_orders.entity_uuid` | `unsignedBigInteger('invoice_id')` | **`uuid`** |
| `influencer_domains.influencer_id` | `bigint` FK（NOT NULL） | 同 v1 | **`text 'influencer_name'`**（保留 ERP 原貌，避免 NULL/孤儿行 COPY 失败） |
| `influencers.id` | `uuid` | `bigint $table->id()` | **`uuid`** |
| `attachments.id` | `bigserial` | `bigint $table->id()` | **`bigserial`**（保留 ERP 原貌） |
| `paypal_*.id` | `bigserial` | `bigint $table->id()` | **`bigserial`**（保留 ERP 原貌） |
| `invoice_orders.id` | `bigserial` | `bigint $table->id()` | **`bigserial`**（保留 ERP 原貌） |
| `daily_stats.id` | `bigserial` | `bigint $table->id()` | **`bigserial`**（保留 ERP 原貌） |
| `exchange_rates.id` | `bigserial` | `bigint $table->id()` | **`bigserial`**（保留 ERP 原貌） |

### 1.2 设计要点

#### 1.2.1 时间戳三件套（强制追加）

所有非 RBAC 业务表统一追加三个时间戳列：

| 字段名 | 类型 | 是否可空 | 默认值 | 说明 |
|---|---|---|---|---|
| `created_at` | `timestamptz(6)` | `NOT NULL` | `CURRENT_TIMESTAMP` | 记录创建时间；与原表已有 `created_at` 类型兼容，不存在时新增 |
| `updated_at` | `timestamptz(6)` | `NOT NULL` | `CURRENT_TIMESTAMP` | 记录更新时间；不存在时新增 |
| `deleted_at` | `timestamptz(6)` | `NULL` | 无 | 软删除时间戳；用于 Laravel `SoftDeletes` trait |

> 原表已有 `created_at`/`updated_at` 的（例如 `daily_stats`、`orders`），按 `ALTER ... TYPE timestamptz(6)` 升级类型，并 `SET DEFAULT CURRENT_TIMESTAMP`。

#### 1.2.2 主键与外键原则

- **保留原貌**：不在本次迁移中做"bigint ↔ uuid"的跨体系转换。
- UUID 主表的 FK 引用 `xxx.entity_uuid`；bigserial 主表的 FK 引用 `xxx.id`。
- 引用 `users` 的 FK 按业务字段决定（`users.id` 走 bigserial 域，`users.entity_uuid` 走 UUID 域）。
- 不再将 `influencer_domains.influencer_id` 变成 NOT NULL bigint FK，而保留 `influencer_name text`（与 ERP 严格一致）——这样 ERP 里"未指派 Influencer 的孤儿域名"会原样迁移，不会再被 COPY 拒绝。

#### 1.2.3 Laravel 集成

- 所有表支持 `use SoftDeletes;`（用 `deleted_at`）。
- 所有表支持 `$timestamps = true`（用 `created_at`/`updated_at`）。
- `uuid` 主键列需要在对应的 Eloquent Model 里 `$incrementing = false; $keyType = 'string';`。

---

## 2. 成功落地的 RBAC 表（不动）

下列 7 张表已由 `2026_09_04_180000_create_rbac.php` 成功迁移，结构与迁移前文档一致；本次 v3 迁移**不触碰**：

- `users`
- `api_tokens`
- `roles`
- `permissions`（融合菜单 + 权限点）
- `role_permissions`
- `user_roles`
- `audit_logs`

> 字段说明 + 建表 SQL 与 RBAC 迁移文件完全对齐，便于历史追溯与跨域对照。

### 2.1 `users` — 用户账户

> **v3 关键说明**：`users` 表由 `2026_09_04_180000_create_rbac.php` 已创建，含 RBAC 必填字段（`role_id`、`permission_id` 等）。本表**同时存在 ERP 域的稳定 UUID `entity_uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid()`**（来自 `sql.md` 与字典 §9.1 原貌），用于跨域关联。脚本会先 `ALTER TABLE` 把该列加上，不重建 RBAC 表。下表"完整结构"列出的是**生产实际生效的并集**（RBAC 字段 ∪ ERP 字段）。

#### 2.1.1 字段说明

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | `bigserial` | 主键，自增序列 |
| `username` | `varchar(64)` | 登录用户名，**唯一约束** |
| `password_hash` | `varchar(255)` | 密码 bcrypt/argon2 哈希值，严禁明文 |
| `display_name` | `varchar(100)` | 界面显示的用户名称 |
| `staff_code` | `varchar(64)` NULL | 关联员工编码 |
| `active` | `boolean` | 账户是否启用；`true`=启用，`false`=禁用 |
| `must_change_password` | `boolean` | 下次登录是否强制要求修改密码 |
| `role_id` | `bigint` NULL | RBAC v3 角色外键，`FK → roles.id`（`SET NULL`），供 ORM 懒加载 |
| `entity_uuid` | `uuid` NOT NULL DEFAULT `gen_random_uuid()` | ERP 原貌字段，**唯一约束**，跨域引用使用此列 |
| `created_at` | `timestamptz(6)` |  |
| `updated_at` | `timestamptz(6)` |  |
| `deleted_at` | `timestamptz(6)` NULL | 软删除 |

```sql
-- 下面这段是 v3 真正会落地的"生产表结构"描述，并集所有列：
CREATE TABLE users (
    id                     bigint          NOT NULL,
    username               varchar(64)     NOT NULL,
    password_hash          varchar(255)    NOT NULL,
    display_name           varchar(100)    NOT NULL,
    staff_code             varchar(64)     NULL,
    active                 boolean         NOT NULL DEFAULT true,
    must_change_password   boolean         NOT NULL DEFAULT false,
    role_id                bigint          NULL,
    entity_uuid            uuid            NOT NULL,
    created_at             timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at             timestamptz(6)  NULL,

    CONSTRAINT users_pkey                 PRIMARY KEY (id),
    CONSTRAINT users_username_unique      UNIQUE (username),
    CONSTRAINT users_entity_uuid_unique   UNIQUE (entity_uuid),
    CONSTRAINT users_role_id_fkey         FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
);
CREATE INDEX users_role_id_index ON users(role_id);
CREATE INDEX users_staff_code_index ON users(staff_code);
```

> 落地说明：实际迁移时，先由 `2026_09_04_180000_create_rbac.php` 建好 `users` 基础列与 `role_id`，再由独立的 `add_users_entity_uuid` 迁移为表新增 `entity_uuid uuid NOT NULL DEFAULT gen_random_uuid()` 列与 `users_entity_uuid_unique`，老用户回填 `gen_random_uuid()`。

### 2.2 `api_tokens` — API 认证 Token

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | `bigserial` | 主键 |
| `user_id` | `bigint` | 所属用户；`FK → users.id`，级联删除 |
| `token_hash` | `varchar(64)` | Token 明文 SHA-256 哈希，**唯一** |
| `name` | `varchar(100)` NULL | Token 标签（"iPhone"、"CI" 等） |
| `abilities` | `jsonb` NULL | Token 权限范围 |
| `ip` | `varchar(45)` NULL | 签发时客户端 IP |
| `user_agent` | `varchar(500)` NULL | 签发时 UA |
| `last_used_at` | `timestamptz` NULL | 最近使用时间 |
| `expires_at` | `timestamptz` NULL | 过期时间；`NULL`=永不过期 |
| `created_at` | `timestamptz(6)` |  |
| `updated_at` | `timestamptz(6)` |  |
| `deleted_at` | `timestamptz(6)` NULL | 软删除 |

```sql
CREATE TABLE api_tokens (
    id              bigint          NOT NULL,
    user_id         bigint          NOT NULL,
    token_hash      varchar(64)     NOT NULL,
    name            varchar(100)    NULL,
    abilities       jsonb           NULL,
    ip              varchar(45)     NULL,
    user_agent      varchar(500)    NULL,
    last_used_at    timestamptz     NULL,
    expires_at      timestamptz     NULL,
    created_at      timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      timestamptz(6)  NULL,

    CONSTRAINT api_tokens_pkey           PRIMARY KEY (id),
    CONSTRAINT api_tokens_token_hash_unique UNIQUE (token_hash),
    CONSTRAINT api_tokens_user_id_fkey   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX api_tokens_user_id_index ON api_tokens(user_id);
CREATE INDEX api_tokens_expires_at_index ON api_tokens(expires_at) WHERE expires_at IS NOT NULL;
```

### 2.3 `roles` — RBAC 角色

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | `bigserial` | 主键 |
| `code` | `varchar(64)` | 角色代码，**全局唯一** |
| `name` | `varchar(100)` | 角色显示名称（英文/默认） |
| `name_zh` | `varchar(100)` NULL | 角色中文显示名称 |
| `description` | `varchar(255)` NULL | 角色描述（英文/默认） |
| `description_zh` | `varchar(255)` NULL | 角色中文描述 |
| `status` | `smallint` | 状态：1=启用，0=禁用 |
| `is_system` | `boolean` | 是否内置角色 |
| `sort` | `integer` | 排序权重 |
| `created_at` | `timestamptz(6)` |  |
| `updated_at` | `timestamptz(6)` |  |
| `deleted_at` | `timestamptz(6)` NULL | 软删除 |

```sql
CREATE TABLE roles (
    id              bigint          NOT NULL,
    code            varchar(64)     NOT NULL,
    name            varchar(100)    NOT NULL,
    name_zh         varchar(100)    NULL,
    description     varchar(255)    NULL,
    description_zh  varchar(255)    NULL,
    status          smallint        NOT NULL DEFAULT 1,
    is_system       boolean         NOT NULL DEFAULT false,
    sort            integer         NOT NULL DEFAULT 0,
    created_at      timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      timestamptz(6)  NULL,

    CONSTRAINT roles_pkey       PRIMARY KEY (id),
    CONSTRAINT roles_code_unique UNIQUE (code)
);
CREATE INDEX roles_status_sort_index ON roles(status, sort);
```

### 2.4 `permissions` — 融合"菜单 + 权限点"为一棵树

- `type='menu'` ：在导航可见，用于前端渲染菜单树
- `type='action'`：纯权限点，不出现在导航，仅用于后端 API 校验
- `parent_id`：自引用树形结构，`0` = 顶级
- `role_permissions` 既可以挂菜单节点（控制"能看什么菜单"），也可以挂 action（控制"能点哪些按钮/调哪些 API"）

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | `bigserial` | 主键，自增序列 |
| `parent_id` | `bigint` | 父节点 ID；0=顶级 |
| `code` | `varchar(100)` | 权限代码，**全局唯一**（菜单如 `system.user`；动作如 `user.create`） |
| `name` | `varchar(100)` | 显示名称（英文/默认） |
| `name_zh` | `varchar(100)` NULL | 中文显示名称 |
| `type` | `varchar(16)` | 节点类型：`menu` / `action` |
| `path` | `varchar(255)` NULL | 前端路由路径，仅 menu 有意义（如 `/system/users`） |
| `icon` | `varchar(64)` NULL | 菜单图标 |
| `component` | `varchar(255)` NULL | Vue 组件路径 |
| `action` | `varchar(32)` | 操作类型：`list/create/update/delete/export/custom`；仅 action 有意义 |
| `resource` | `varchar(100)` NULL | 资源标识，用于资源级权限控制 |
| `level` | `smallint` | 树层级：1=模块，2=页面，3=按钮 |
| `is_menu_visible` | `boolean` | 是否在侧边栏导航中显示 |
| `hidden` | `boolean` | 隐藏菜单节点（仍可访问，仅不显示） |
| `sort` | `integer` | 同级排序权重 |
| `status` | `smallint` | 状态：1=启用，0=禁用 |
| `description` | `varchar(255)` NULL | 描述（英文/默认） |
| `description_zh` | `varchar(255)` NULL | 中文描述 |
| `created_at` | `timestamptz(6)` |  |
| `updated_at` | `timestamptz(6)` |  |
| `deleted_at` | `timestamptz(6)` NULL | 软删除 |

```sql
CREATE TABLE permissions (
    id                bigint          NOT NULL,
    parent_id         bigint          NOT NULL DEFAULT 0,
    code              varchar(100)    NOT NULL,
    name              varchar(100)    NOT NULL,
    name_zh           varchar(100)    NULL,
    type              varchar(16)     NOT NULL DEFAULT 'menu',
    path              varchar(255)    NULL,
    icon              varchar(64)     NULL,
    component         varchar(255)    NULL,
    action            varchar(32)     NOT NULL DEFAULT 'custom',
    resource          varchar(100)    NULL,
    level             smallint        NOT NULL DEFAULT 1,
    is_menu_visible   boolean         NOT NULL DEFAULT true,
    hidden            boolean         NOT NULL DEFAULT false,
    sort              integer         NOT NULL DEFAULT 0,
    status            smallint        NOT NULL DEFAULT 1,
    description       varchar(255)    NULL,
    description_zh    varchar(255)    NULL,
    created_at        timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at        timestamptz(6)  NULL,

    CONSTRAINT permissions_pkey       PRIMARY KEY (id),
    CONSTRAINT permissions_code_unique UNIQUE (code)
);
CREATE INDEX permissions_parent_id_sort_index ON permissions(parent_id, sort);
CREATE INDEX permissions_type_status_index   ON permissions(type, status);
CREATE INDEX permissions_level_status_index  ON permissions(level, status);
```

### 2.5 `role_permissions` — 角色 ↔ 权限（菜单/按钮）

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | `bigserial` | 主键 |
| `role_id` | `bigint` | 角色 ID；`FK → roles.id`，级联删除 |
| `permission_id` | `bigint` | 权限 ID（菜单或 action）；`FK → permissions.id`，级联删除 |
| `created_at` | `timestamptz(6)` |  |
| `updated_at` | `timestamptz(6)` |  |

```sql
CREATE TABLE role_permissions (
    id              bigint          NOT NULL,
    role_id         bigint          NOT NULL,
    permission_id   bigint          NOT NULL,
    created_at      timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT role_permissions_pkey PRIMARY KEY (id),
    CONSTRAINT role_permissions_role_fkey        FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    CONSTRAINT role_permissions_permission_fkey  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    CONSTRAINT role_permissions_unique            UNIQUE (role_id, permission_id)
);
CREATE INDEX role_permissions_permission_id_index ON role_permissions(permission_id);
```

### 2.6 `user_roles` — 用户 ↔ 角色

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | `bigserial` | 主键 |
| `user_id` | `bigint` | 用户 ID；`FK → users.id`，级联删除 |
| `role_id` | `bigint` | 角色 ID；`FK → roles.id`，级联删除 |
| `granted_by_user_id` | `bigint` NULL | 授权人；`FK → users.id`，`SET NULL` |
| `created_at` | `timestamptz(6)` |  |
| `updated_at` | `timestamptz(6)` |  |

```sql
CREATE TABLE user_roles (
    id                    bigint          NOT NULL,
    user_id               bigint          NOT NULL,
    role_id               bigint          NOT NULL,
    granted_by_user_id    bigint          NULL,
    created_at            timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT user_roles_pkey                 PRIMARY KEY (id),
    CONSTRAINT user_roles_user_fkey            FOREIGN KEY (user_id)            REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT user_roles_role_fkey            FOREIGN KEY (role_id)            REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT user_roles_granted_by_fkey      FOREIGN KEY (granted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT user_roles_user_role_unique      UNIQUE (user_id, role_id)
);
CREATE INDEX user_roles_role_id_index ON user_roles(role_id);
```

### 2.7 `audit_logs` — 审计日志

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | `bigserial` | 主键 |
| `user_id` | `bigint` NULL | 操作用户 ID |
| `action` | `varchar(64)` | 操作类型（如 `user.login` / `order.update`） |
| `entity_type` | `varchar(64)` | 实体类型 |
| `entity_id` | `varchar(64)` NULL | 实体 ID（支持 UUID / bigint 字符串） |
| `ip` | `varchar(45)` NULL | 客户端 IP |
| `details` | `text` NULL | JSON 格式操作详情 |
| `created_at` | `timestamptz` NULL | 操作时间 |

```sql
CREATE TABLE audit_logs (
    id           bigint          NOT NULL,
    user_id      bigint          NULL,
    action       varchar(64)     NOT NULL,
    entity_type  varchar(64)     NOT NULL,
    entity_id    varchar(64)     NULL,
    ip           varchar(45)     NULL,
    details      text            NULL,
    created_at   timestamptz     NULL,

    CONSTRAINT audit_logs_pkey PRIMARY KEY (id)
);
CREATE INDEX audit_logs_user_id_index        ON audit_logs(user_id);
CREATE INDEX audit_logs_entity_index         ON audit_logs(entity_type, entity_id);
CREATE INDEX audit_logs_created_at_index     ON audit_logs(created_at);
```

### 2.8 内置角色种子

迁移同时内置以下三条 system role（id 固定，便于跨环境引用）：

| id | code | name | sort | is_system |
|----|------|------|------|-----------|
| 1 | `super_admin` | Super Administrator | 0  | true |
| 2 | `admin`       | Administrator       | 10 | true |
| 3 | `viewer`      | Read-only Viewer    | 90 | true |


---

## 3. 订单与统计

> 本章四张表的字段类型与唯一/外键约束均与 `sql.md` `§4` + `saveb-erp-database-schema-data-dictionary-20260904.md` `§3` 一一对齐（业务原貌）。在原表上**额外新增** `created_at`、`updated_at`、`deleted_at` 三个时间戳列（原本就有 `created_at`/`updated_at` 的表只补 `deleted_at`）。

### 3.1 `orders` — 订单主表

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` | 序列 | 历史内部主键 |
| `order_id` | `text` NN | — | ERP 订单稳定标识，唯一 |
| `paypal_order_id` | `text` | — | PayPal 侧订单标识 |
| `order_time` | `timestamptz` | — | 订单业务时间 |
| `customer_name` | `text` | — | 客户姓名；敏感字段 |
| `source_site` | `text` | — | 来源站点/域名 |
| `classification` | `text` | — | 业务归类 |
| `influencer_name` | `text` | — | 归属 Influencer 名称快照 |
| `receiving_paypal` | `text` | — | 收款 PayPal；敏感字段 |
| `amount_original` | `numeric(14,2)` | — | 原币金额 |
| `currency` | `text` | — | 原币币种 |
| `amount_usd` | `numeric(14,2)` | — | 折算美元金额 |
| `items_count` | `integer` NN | 1 | 商品件数 |
| `product_name` | `text` | — | 历史兼容商品名称 |
| `order_status` | `text` | — | 订单当前状态 |
| `staff_code` | `text` | — | 主负责人编码 |
| `raw` | `jsonb` NN | `{}` | 上游原始订单快照；兼容/追溯用途 |
| `client_order_id` | `text` | — | 客户侧订单标识，唯一 |
| `entity_uuid` | `uuid` NN | `gen_random_uuid()` | 新域稳定 UUID，唯一 |
| `visible_order_id` | `bigint` NN | 序列 | 面向界面/人员的顺序号，唯一 |
| `version` | `integer` NN | 1 | 乐观锁版本 |
| `created_at` | `timestamptz(6)` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz(6)` NN | 当前时间 | 最近更新时间 |
| `deleted_at` | `timestamptz(6)` NULL | — | 软删除（v3 新增） |

```sql
CREATE SEQUENCE saveb_visible_order_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE orders (
    id                  bigint          NOT NULL DEFAULT nextval('orders_id_seq'),
    order_id            text            NOT NULL,
    paypal_order_id     text            NULL,
    order_time          timestamptz     NULL,
    customer_name       text            NULL,
    source_site         text            NULL,
    classification      text            NULL,
    influencer_name     text            NULL,
    receiving_paypal    text            NULL,
    amount_original     numeric(14,2)   NULL,
    currency            text            NULL,
    amount_usd          numeric(14,2)   NULL,
    items_count         integer         NOT NULL DEFAULT 1,
    product_name        text            NULL,
    order_status        text            NULL,
    staff_code          text            NULL,
    raw                 jsonb           NOT NULL DEFAULT '{}'::jsonb,
    client_order_id     text            NULL,
    entity_uuid         uuid            NOT NULL DEFAULT gen_random_uuid(),
    visible_order_id    bigint          NOT NULL DEFAULT nextval('saveb_visible_order_id_seq'),
    version             integer         NOT NULL DEFAULT 1,
    created_at          timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at          timestamptz     NULL,

    CONSTRAINT orders_pkey                    PRIMARY KEY (id),
    CONSTRAINT orders_order_id_unique         UNIQUE (order_id),
    CONSTRAINT orders_entity_uuid_unique      UNIQUE (entity_uuid),
    CONSTRAINT orders_visible_order_id_unique UNIQUE (visible_order_id),
    CONSTRAINT orders_client_order_id_unique  UNIQUE (client_order_id)
);
CREATE INDEX idx_orders_time           ON orders(order_time);
CREATE INDEX idx_orders_status         ON orders(order_status);
CREATE INDEX idx_orders_staff          ON orders(staff_code);
CREATE INDEX idx_orders_paypal         ON orders(paypal_order_id);
CREATE INDEX idx_orders_class          ON orders(classification);
CREATE INDEX idx_orders_client_order_id ON orders(client_order_id);
CREATE INDEX idx_orders_entity_uuid    ON orders(entity_uuid);
```

> 落地说明：`orders` 已由 `2026_09_05_120000_create_orders.php` + `2026_09_05_120001_relax_orders_columns_for_erp_sync.php` 落地于先前的 v3 迁移历史；本次 v3 不重建，仅描述"实际生产结构"以保证文档与物理表一致。

---

### 3.2 `order_items` — 订单商品明细

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `order_uuid` | `uuid` NN | — | 所属订单，`FK → orders.entity_uuid`（级联删除） |
| `sku` | `text` | — | 商品 SKU |
| `product_name` | `text` NN | — | 商品名称 |
| `quantity` | `integer` NN | 1 | 数量 |
| `unit_price` | `numeric(14,2)` | — | 单价 |
| `currency` | `text` | — | 单价币种 |
| `metadata` | `jsonb` NN | `{}` | 商品扩展属性 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v3 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v3 新增） |

```sql
CREATE TABLE order_items (
    id              uuid           NOT NULL DEFAULT gen_random_uuid(),
    order_uuid      uuid           NOT NULL,
    sku             text           NULL,
    product_name    text           NOT NULL,
    quantity        integer        NOT NULL DEFAULT 1,
    unit_price      numeric(14,2)  NULL,
    currency        text           NULL,
    metadata        jsonb          NOT NULL DEFAULT '{}'::jsonb,
    created_at      timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      timestamptz    NULL,

    CONSTRAINT order_items_pkey          PRIMARY KEY (id),
    CONSTRAINT order_items_order_uuid_fk FOREIGN KEY (order_uuid) REFERENCES orders(entity_uuid) ON DELETE CASCADE
);
CREATE INDEX idx_order_items_order_uuid ON order_items(order_uuid);
```

> ERP 当前 `order_items` **零行**（仅有建表），v3 迁移脚本对这张表无需要回填的内容。

---

### 3.3 `daily_stats` — 每日统计

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `stat_date` | `date` NN | — | 业务统计日 |
| `channel` | `text` NN | — | 渠道维度 |
| `staff_code` | `text` NN | `''` | 员工维度（空串 = 汇总口径） |
| `orders_count` | `numeric(18,10)` NN | 0 | 按份额计算的订单数 |
| `items_count` | `numeric(18,10)` NN | 0 | 按份额计算的件数 |
| `usd_amount` | `numeric(18,10)` NN | 0 | 按份额计算的美元金额 |
| `updated_at` | `timestamptz` NN | 当前时间 | 汇总刷新时间 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间（v3 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v3 新增） |

```sql
CREATE SEQUENCE daily_stats_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

CREATE TABLE daily_stats (
    id            bigint          NOT NULL DEFAULT nextval('daily_stats_id_seq'),
    stat_date     date            NOT NULL,
    channel       text            NOT NULL,
    staff_code    text            NOT NULL DEFAULT '',
    orders_count  numeric(18,10)  NOT NULL DEFAULT 0,
    items_count   numeric(18,10)  NOT NULL DEFAULT 0,
    usd_amount    numeric(18,10)  NOT NULL DEFAULT 0,
    updated_at    timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at    timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at    timestamptz     NULL,

    CONSTRAINT daily_stats_pkey                        PRIMARY KEY (id),
    CONSTRAINT daily_stats_date_channel_staff_unique   UNIQUE (stat_date, channel, staff_code)
);
```

---

### 3.4 `exchange_rates` — 汇率表

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `currency` | `text` NN | — | 币种代码 |
| `rate_to_usd` | `numeric(18,8)` NN | — | 兑美元汇率 |
| `effective_date` | `date` NN | — | 生效日期 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间（v3 新增） |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v3 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v3 新增） |

```sql
CREATE SEQUENCE exchange_rates_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE exchange_rates (
    id              bigint          NOT NULL DEFAULT nextval('exchange_rates_id_seq'),
    currency        text            NOT NULL,
    rate_to_usd     numeric(18,8)   NOT NULL,
    effective_date  date            NOT NULL,
    created_at      timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      timestamptz     NULL,

    CONSTRAINT exchange_rates_pkey                 PRIMARY KEY (id),
    CONSTRAINT exchange_rates_currency_date_unique UNIQUE (currency, effective_date)
);
```
    CONSTRAINT exchange_rates_currency_date_unique   UNIQUE (currency, effective_date)
);
```

- 字段原貌：`numeric(18,8)`。
- `currency + effective_date` 组合唯一。

---

## 4. Pending 完成与绩效

> 本章五张表的字段、CHECK 约束、外键策略均与 `sql.md` §5-§9（v2 schema）+ 数据字典 §4 完全一致。v4 在原貌基础上**新增** `deleted_at`（原本已有 `created_at`/`updated_at` 的表），并把 4.1/4.3 的 `timestamptz` 类型补齐为 PG `timestamptz`（等价于 ERP）。下游投影表（4.4/4.5）的"非基线数据"由迁移脚本控制是否回填，本节只描述结构。

### 4.1 `order_user_overrides` — 订单主负责人覆盖表

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `order_key` | `text` NN | — | 被覆盖订单的稳定身份值 |
| `order_key_type` | `text` NN | — | 身份类型：`client`/`order`/`paypal`（CHECK） |
| `order_uuid` | `uuid` | — | 解析后的正式订单，`FK → orders.entity_uuid`，删除订单时置空 |
| `source_status` | `text` NN | — | 覆盖前状态 |
| `status_override` | `text` NN | — | 覆盖后状态，CHECK = `completed` |
| `primary_staff_code` | `text` NN | — | 主负责人编码 |
| `version` | `integer` NN | 1 | 乐观锁版本 |
| `updated_by_user_uuid` | `uuid` NN | — | 最后修改用户，`FK → users.entity_uuid` |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间 |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE order_user_overrides (
    id                      uuid           NOT NULL DEFAULT gen_random_uuid(),
    order_key               text           NOT NULL,
    order_key_type          text           NOT NULL,
    order_uuid              uuid           NULL,
    source_status           text           NOT NULL,
    status_override         text           NOT NULL,
    primary_staff_code      text           NOT NULL,
    version                 integer        NOT NULL DEFAULT 1,
    updated_by_user_uuid    uuid           NOT NULL,
    created_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              timestamptz    NULL,

    CONSTRAINT order_user_overrides_pkey                 PRIMARY KEY (id),
    CONSTRAINT order_user_overrides_order_uuid_fk        FOREIGN KEY (order_uuid)           REFERENCES orders(entity_uuid) ON DELETE SET NULL,
    CONSTRAINT order_user_overrides_updated_by_user_fk   FOREIGN KEY (updated_by_user_uuid) REFERENCES users(entity_uuid),
    CONSTRAINT order_user_override_identity_unique       UNIQUE (order_key, order_key_type),
    CONSTRAINT order_user_override_key_type_chk          CHECK (order_key_type IN ('client', 'order', 'paypal')),
    CONSTRAINT order_user_override_completed_chk         CHECK (status_override = 'completed'),
    CONSTRAINT order_user_override_version_chk           CHECK (version > 0)
);
CREATE INDEX idx_order_user_overrides_order_uuid ON order_user_overrides(order_uuid);
CREATE INDEX idx_order_user_overrides_updated_at ON order_user_overrides(updated_at DESC);
```

> ERP 当前 `order_user_overrides` 76 行；FK `updated_by_user_uuid → users.entity_uuid` 在迁移时按 ERP 用户 UUID 直接 INSERT，不做 API 用户 ID 解析。

---

### 4.2 `order_staff_allocations` — 订单人员分摊表

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `order_override_id` | `uuid` NN | — | 所属覆盖记录，`FK → order_user_overrides.id`（级联） |
| `staff_code` | `text` NN | — | 员工编码 |
| `participant_role` | `text` NN | — | `primary` / `collaborator`（CHECK） |
| `share_ratio` | `numeric(12,10)` NN | — | 绩效分摊比例，CHECK ∈ (0, 1] |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE order_staff_allocations (
    id                  uuid           NOT NULL DEFAULT gen_random_uuid(),
    order_override_id   uuid           NOT NULL,
    staff_code          text           NOT NULL,
    participant_role    text           NOT NULL,
    share_ratio         numeric(12,10) NOT NULL,
    created_at          timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at          timestamptz    NULL,

    CONSTRAINT order_staff_allocations_pkey          PRIMARY KEY (id),
    CONSTRAINT order_staff_allocation_override_fk    FOREIGN KEY (order_override_id) REFERENCES order_user_overrides(id) ON DELETE CASCADE,
    CONSTRAINT order_staff_allocation_unique         UNIQUE (order_override_id, staff_code),
    CONSTRAINT order_staff_allocation_role_chk       CHECK (participant_role IN ('primary', 'collaborator')),
    CONSTRAINT order_staff_allocation_share_chk      CHECK (share_ratio > 0 AND share_ratio <= 1)
);
CREATE INDEX idx_order_staff_allocations_staff ON order_staff_allocations(staff_code);
```

---

### 4.3 `pending_completion_operations` — Pending→Completed 业务结果

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `operation_uuid` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `identity_type` | `text` NN | — | `client` / `order` / `paypal`（CHECK） |
| `identity_key` | `text` NN | — | 订单稳定身份值 |
| `order_uuid` | `uuid` NN | — | 目标正式订单，`FK → orders.entity_uuid`（级联） |
| `business_date` | `date` NN | — | 业务归属日 |
| `source_status` | `text` NN | — | 操作前状态，CHECK = `pending` |
| `target_status` | `text` NN | — | 操作后状态，CHECK = `completed` |
| `target_classification` | `text` NN | — | `payment_link`（CHECK） |
| `result` | `jsonb` NN | — | 操作结果快照 |
| `completed_at` | `timestamptz` NN | — | 完成时间 |
| `created_at` | `timestamptz` NN | 当前时间 | 记录创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE pending_completion_operations (
    operation_uuid         uuid           NOT NULL DEFAULT gen_random_uuid(),
    identity_type          text           NOT NULL,
    identity_key           text           NOT NULL,
    order_uuid             uuid           NOT NULL,
    business_date          date           NOT NULL,
    source_status          text           NOT NULL,
    target_status          text           NOT NULL,
    target_classification  text           NOT NULL,
    result                 jsonb          NOT NULL,
    completed_at           timestamptz    NOT NULL,
    created_at             timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at             timestamptz    NULL,

    CONSTRAINT pending_completion_pkey              PRIMARY KEY (operation_uuid),
    CONSTRAINT pending_completion_order_uuid_fk     FOREIGN KEY (order_uuid) REFERENCES orders(entity_uuid) ON DELETE CASCADE,
    CONSTRAINT pending_completion_identity_unique   UNIQUE (identity_type, identity_key),
    CONSTRAINT pending_completion_order_unique      UNIQUE (order_uuid),
    CONSTRAINT pending_completion_identity_type_chk CHECK (identity_type IN ('client', 'order', 'paypal')),
    CONSTRAINT pending_completion_source_chk        CHECK (source_status = 'pending'),
    CONSTRAINT pending_completion_target_chk        CHECK (target_status = 'completed'),
    CONSTRAINT pending_completion_classification_chk CHECK (target_classification = 'payment_link')
);
```

---

### 4.4 `order_status_observations` — 状态观测事件

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `order_uuid` | `uuid` NN | — | 正式订单，`FK → orders.entity_uuid`（级联） |
| `identity_type` | `text` NN | — | 观测使用的身份类型 |
| `identity_key` | `text` NN | — | 观测使用的身份值 |
| `source_system` | `text` NN | `saveb_erp` | 来源系统 |
| `order_source_stable_key` | `text` NN | — | 来源系统稳定订单键 |
| `status` | `text` NN | — | 上游原始状态 |
| `normalized_status` | `text` NN | — | 归一化状态 |
| `classification` | `text` | — | 观测时归类 |
| `source_business_time` | `timestamptz` | — | 上游业务时间 |
| `observed_at` | `timestamptz` NN | — | 系统观测时间 |
| `source` | `text` NN | — | 观测来源/触发路径 |
| `operation_uuid` | `uuid` | — | 对应完成操作，`FK → pending_completion_operations.operation_uuid`（置空），UNIQUE |
| `bounded_projection` | `jsonb` NN | `{}` | 有界投影结果 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE order_status_observations_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE order_status_observations (
    id                       bigint          NOT NULL DEFAULT nextval('order_status_observations_id_seq'),
    order_uuid               uuid            NOT NULL,
    identity_type            text            NOT NULL,
    identity_key             text            NOT NULL,
    source_system            text            NOT NULL DEFAULT 'saveb_erp',
    order_source_stable_key  text            NOT NULL,
    status                   text            NOT NULL,
    normalized_status        text            NOT NULL,
    classification           text            NULL,
    source_business_time     timestamptz     NULL,
    observed_at              timestamptz     NOT NULL,
    source                   text            NOT NULL,
    operation_uuid           uuid            NULL,
    bounded_projection       jsonb           NOT NULL DEFAULT '{}'::jsonb,
    created_at               timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at               timestamptz     NULL,

    CONSTRAINT order_status_observations_pkey              PRIMARY KEY (id),
    CONSTRAINT order_status_observations_order_uuid_fk     FOREIGN KEY (order_uuid)     REFERENCES orders(entity_uuid)                         ON DELETE CASCADE,
    CONSTRAINT order_status_observations_operation_uuid_fk FOREIGN KEY (operation_uuid) REFERENCES pending_completion_operations(operation_uuid) ON DELETE SET NULL,
    CONSTRAINT order_status_observation_operation_unique   UNIQUE (operation_uuid)
);
CREATE INDEX idx_order_status_observation_stable_timeline ON order_status_observations(order_source_stable_key, observed_at DESC);
CREATE INDEX idx_order_status_observation_business_status ON order_status_observations(normalized_status, observed_at DESC);
```

---

### 4.5 `order_staff_performance_projection` — 人员绩效投影

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `operation_uuid` | `uuid` NN | — | 来源操作，`FK → pending_completion_operations`（级联） |
| `order_uuid` | `uuid` NN | — | 来源订单，`FK → orders.entity_uuid`（级联） |
| `business_date` | `date` NN | — | 绩效归属日 |
| `staff_code` | `text` NN | — | 员工编码 |
| `share_ratio` | `numeric(12,10)` NN | — | 员工份额，CHECK ∈ (0, 1] |
| `orders_basis` | `numeric(18,10)` NN | — | 订单数投影基数 |
| `items_basis` | `numeric(18,10)` NN | — | 件数投影基数 |
| `amount_usd_basis` | `numeric(18,10)` NN | — | 美元金额投影基数 |
| `commission_percent` | `numeric(12,6)` | — | 佣金比例，CHECK ≥ 0 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE order_staff_performance_projection_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE order_staff_performance_projection (
    id                    bigint          NOT NULL DEFAULT nextval('order_staff_performance_projection_id_seq'),
    operation_uuid        uuid            NOT NULL,
    order_uuid            uuid            NOT NULL,
    business_date         date            NOT NULL,
    staff_code            text            NOT NULL,
    share_ratio           numeric(12,10)  NOT NULL,
    orders_basis          numeric(18,10)  NOT NULL,
    items_basis           numeric(18,10)  NOT NULL,
    amount_usd_basis      numeric(18,10)  NOT NULL,
    commission_percent    numeric(12,6)   NULL,
    created_at            timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at            timestamptz     NULL,

    CONSTRAINT order_staff_performance_projection_pkey         PRIMARY KEY (id),
    CONSTRAINT order_staff_performance_projection_operation_fk FOREIGN KEY (operation_uuid) REFERENCES pending_completion_operations(operation_uuid) ON DELETE CASCADE,
    CONSTRAINT order_staff_performance_projection_order_fk     FOREIGN KEY (order_uuid)     REFERENCES orders(entity_uuid)                        ON DELETE CASCADE,
    CONSTRAINT order_staff_performance_projection_unique       UNIQUE (operation_uuid, staff_code),
    CONSTRAINT order_staff_performance_projection_share_chk    CHECK (share_ratio > 0 AND share_ratio <= 1),
    CONSTRAINT order_staff_performance_projection_commission_chk CHECK (commission_percent IS NULL OR commission_percent >= 0)
);
CREATE INDEX idx_order_staff_performance_month_staff ON order_staff_performance_projection(business_date, staff_code);
```

---

## 5. 发票

> 本章五张表的字段保留 `sql.md` `§7` + 数据字典 `§5` 原貌，包括 `numeric(14,2)` / `numeric(5,2)` / `numeric(6,4)` / `numeric(12,2)` 精度与原 CHECK 约束。v4 在 `invoice_orders` / `invoice_items` / `invoice_adjustments` / `invoice_staff_allocations` / `invoice_operation_logs` 上**额外** 补 `created_at` / `updated_at` / `deleted_at`。

### 5.1 `invoice_orders` — 发票主表

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 历史内部主键 |
| `legacy_id` | `text` | — | 旧系统记录标识，唯一 |
| `order_number` | `text` NN | — | Invoice 订单号，唯一 |
| `invoice_date` | `date` NN | — | Invoice 日期 |
| `customer_full_name` | `text` | — | 客户全名；敏感 |
| `customer_email` | `text` | — | 客户邮箱；敏感 |
| `phone_number` | `text` | — | 电话；敏感 |
| `country` | `text` | — | 国家/地区 |
| `country_source` | `text` | — | 国家识别来源 |
| `address` | `text` | — | 地址；敏感 |
| `invoice_link` | `text` | — | Invoice 链接 |
| `invoice_status` | `text` NN | — | Invoice 状态 |
| `expedited_shipping` | `boolean` NN | `false` | 是否加急运输 |
| `fixed_discount` | `numeric(14,2)` | — | 固定金额折扣 |
| `percentage_discount` | `numeric(14,2)` | — | 百分比折扣 |
| `gift_box` | `text` NN | `Has` | 礼盒/包装状态 |
| `amount_usd` | `numeric(14,2)` NN | — | Invoice 美元金额 |
| `recipient_paypal` | `text` | — | 收款 PayPal；敏感 |
| `created_by` | `bigint` | — | 创建用户（历史 bigint） |
| `raw` | `jsonb` | — | OCR/导入原始快照 |
| `order_date` | `date` | — | Date Ordered |
| `invoice_screenshot_attachment_id` | `bigint` | — | Invoice 截图附件 ID |
| `entity_uuid` | `uuid` NN | `gen_random_uuid()` | 新域稳定 UUID，唯一 |
| `version` | `integer` NN | 1 | 乐观锁版本 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间 |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE invoice_orders_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE invoice_orders (
    id                                 bigint         NOT NULL DEFAULT nextval('invoice_orders_id_seq'),
    legacy_id                          text           NULL,
    order_number                       text           NOT NULL,
    invoice_date                       date           NOT NULL,
    customer_full_name                 text           NULL,
    customer_email                     text           NULL,
    phone_number                       text           NULL,
    country                            text           NULL,
    country_source                     text           NULL,
    address                            text           NULL,
    invoice_link                       text           NULL,
    invoice_status                     text           NOT NULL,
    expedited_shipping                 boolean        NOT NULL DEFAULT false,
    fixed_discount                     numeric(14,2)  NULL,
    percentage_discount                numeric(14,2)  NULL,
    gift_box                           text           NOT NULL DEFAULT 'Has',
    amount_usd                         numeric(14,2)  NOT NULL,
    recipient_paypal                   text           NULL,
    created_by                         bigint         NULL,
    raw                                jsonb          NULL,
    order_date                         date           NULL,
    invoice_screenshot_attachment_id   bigint         NULL,
    entity_uuid                        uuid           NOT NULL DEFAULT gen_random_uuid(),
    version                            integer        NOT NULL DEFAULT 1,
    created_at                         timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                         timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at                         timestamptz    NULL,

    CONSTRAINT invoice_orders_pkey                  PRIMARY KEY (id),
    CONSTRAINT invoice_orders_entity_uuid_unique   UNIQUE (entity_uuid),
    CONSTRAINT invoice_orders_legacy_id_unique     UNIQUE (legacy_id),
    CONSTRAINT invoice_orders_order_number_unique   UNIQUE (order_number)
);
CREATE INDEX idx_invoice_order_number ON invoice_orders(order_number);
CREATE INDEX idx_invoice_date        ON invoice_orders(invoice_date DESC);
CREATE INDEX idx_invoice_entity_uuid ON invoice_orders(entity_uuid);
```

---

### 5.2 `invoice_items` — Invoice 商品明细

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `invoice_id` | `bigint` NN | — | 所属 Invoice，`FK → invoice_orders.id`（级联） |
| `product_name` | `text` | — | 商品名称 |
| `description` | `text` | — | 商品说明 |
| `quantity` | `integer` NN | 1 | 数量 |
| `price` | `numeric(14,2)` | — | 单价/行金额 |
| `notes` | `text` | — | 备注 |
| `image_attachment_id` | `bigint` | — | 商品图片附件 ID |
| `entity_uuid` | `uuid` NN | `gen_random_uuid()` | 新域稳定 UUID，唯一 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE invoice_items_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE invoice_items (
    id                  bigint          NOT NULL DEFAULT nextval('invoice_items_id_seq'),
    invoice_id          bigint          NOT NULL,
    product_name        text            NULL,
    description         text            NULL,
    quantity            integer         NOT NULL DEFAULT 1,
    price               numeric(14,2)   NULL,
    notes               text            NULL,
    image_attachment_id bigint          NULL,
    entity_uuid         uuid            NOT NULL DEFAULT gen_random_uuid(),
    created_at          timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at          timestamptz     NULL,

    CONSTRAINT invoice_items_pkey               PRIMARY KEY (id),
    CONSTRAINT invoice_items_entity_uuid_unique UNIQUE (entity_uuid),
    CONSTRAINT invoice_items_invoice_id_fk      FOREIGN KEY (invoice_id) REFERENCES invoice_orders(id) ON DELETE CASCADE
);
CREATE INDEX idx_invoice_items_invoice_id ON invoice_items(invoice_id);
```

---

### 5.3 `invoice_adjustments` — Invoice 调整项

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `invoice_uuid` | `uuid` NN | — | 所属 Invoice，`FK → invoice_orders.entity_uuid`（级联） |
| `adjustment_type` | `text` NN | — | `discount` / `credit` / `shipping` / `no_box` / `other`（CHECK） |
| `amount` | `numeric(14,2)` | — | 固定调整金额 |
| `percentage` | `numeric(14,2)` | — | 百分比调整 |
| `reason` | `text` | — | 调整原因 |
| `created_by_user_uuid` | `uuid` | — | 创建用户，`FK → users.entity_uuid` |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE invoice_adjustments (
    id                      uuid           NOT NULL DEFAULT gen_random_uuid(),
    invoice_uuid            uuid           NOT NULL,
    adjustment_type         text           NOT NULL,
    amount                  numeric(14,2)  NULL,
    percentage              numeric(14,2)  NULL,
    reason                  text           NULL,
    created_by_user_uuid    uuid           NULL,
    created_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              timestamptz    NULL,

    CONSTRAINT invoice_adjustments_pkey           PRIMARY KEY (id),
    CONSTRAINT invoice_adjustments_invoice_uuid_fk FOREIGN KEY (invoice_uuid)         REFERENCES invoice_orders(entity_uuid) ON DELETE CASCADE,
    CONSTRAINT invoice_adjustments_user_uuid_fk    FOREIGN KEY (created_by_user_uuid) REFERENCES users(entity_uuid)        ON DELETE SET NULL,
    CONSTRAINT invoice_adjustment_type_chk         CHECK (adjustment_type IN ('discount', 'credit', 'shipping', 'no_box', 'other'))
);
CREATE INDEX idx_invoice_adjustments_invoice_uuid ON invoice_adjustments(invoice_uuid);
```

---

### 5.4 `invoice_staff_allocations` — Invoice 人员分摊

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `invoice_id` | `bigint` NN | — | 所属 Invoice，`FK → invoice_orders.id`（级联） |
| `staff_code` | `text` NN | — | 员工编码 |
| `commission_percent` | `numeric(5,2)` NN | 0 | 佣金百分比 |
| `share_ratio` | `numeric(6,4)` NN | 1 | 分摊比例 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间（v4 新增） |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE invoice_staff_allocations_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE invoice_staff_allocations (
    id                 bigint          NOT NULL DEFAULT nextval('invoice_staff_allocations_id_seq'),
    invoice_id         bigint          NOT NULL,
    staff_code         text            NOT NULL,
    commission_percent numeric(5,2)    NOT NULL DEFAULT 0,
    share_ratio        numeric(6,4)    NOT NULL DEFAULT 1,
    created_at         timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at         timestamptz     NULL,

    CONSTRAINT invoice_staff_allocations_pkey     PRIMARY KEY (id),
    CONSTRAINT invoice_staff_allocations_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoice_orders(id) ON DELETE CASCADE
);
CREATE INDEX idx_invoice_staff_alloc_invoice_id ON invoice_staff_allocations(invoice_id);
```

---

### 5.5 `invoice_operation_logs` — Invoice 变更审计

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `invoice_uuid` | `uuid` NN | — | Invoice，`FK → invoice_orders.entity_uuid`（级联） |
| `action` | `text` NN | — | 操作动作 |
| `before` | `jsonb` | — | 变更前快照 |
| `after` | `jsonb` | — | 变更后快照 |
| `actor_user_uuid` | `uuid` NN | — | 操作者，`FK → users.entity_uuid` |
| `request_id` | `text` | — | 请求追踪标识 |
| `source` | `text` NN | `api` | 操作来源 |
| `created_at` | `timestamptz` NN | 当前时间 | 操作时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增；审计表通常保留但保留入口） |

```sql
CREATE TABLE invoice_operation_logs (
    id                  uuid           NOT NULL DEFAULT gen_random_uuid(),
    invoice_uuid        uuid           NOT NULL,
    action              text           NOT NULL,
    before              jsonb          NULL,
    after               jsonb          NULL,
    actor_user_uuid     uuid           NOT NULL,
    request_id          text           NULL,
    source              text           NOT NULL DEFAULT 'api',
    created_at          timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at          timestamptz    NULL,

    CONSTRAINT invoice_operation_logs_pkey             PRIMARY KEY (id),
    CONSTRAINT invoice_operation_logs_invoice_uuid_fk  FOREIGN KEY (invoice_uuid)    REFERENCES invoice_orders(entity_uuid) ON DELETE CASCADE,
    CONSTRAINT invoice_operation_logs_user_fk          FOREIGN KEY (actor_user_uuid) REFERENCES users(entity_uuid)        ON DELETE CASCADE
);
CREATE INDEX idx_invoice_operation_invoice_time ON invoice_operation_logs(invoice_uuid, created_at DESC);
```

---

## 6. PayPal

> 本章四张表的字段精度（`numeric(14,2)`）与索引策略保留 ERP 原貌。v4 在原表上**新增** `created_at` / `updated_at` / `deleted_at`，并保留 ERP 字段名 `entered_by` / `created_by`，**不重命名为** `entered_by_user_id` / `created_by_user_id`（v3 列名映射见 §10）。`bigint` 外键 → `users.id` 也不引入。

### 6.1 `paypal_accounts` — PayPal 账号主数据

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `email` | `text` NN | — | PayPal 邮箱；敏感，唯一 |
| `account_name` | `text` | — | 账号显示名称 |
| `added_date` | `date` | — | 添加日期 |
| `active` | `boolean` NN | `true` | 是否启用 |
| `meta` | `jsonb` NN | `{}` | 扩展元数据 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间（v4 新增） |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE paypal_accounts_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE paypal_accounts (
    id            bigint          NOT NULL DEFAULT nextval('paypal_accounts_id_seq'),
    email         text            NOT NULL,
    account_name  text            NULL,
    added_date    date            NULL,
    active        boolean         NOT NULL DEFAULT true,
    meta          jsonb           NOT NULL DEFAULT '{}'::jsonb,
    created_at    timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at    timestamptz     NULL,

    CONSTRAINT paypal_accounts_pkey          PRIMARY KEY (id),
    CONSTRAINT paypal_accounts_email_unique  UNIQUE (email)
);
```

---

### 6.2 `paypal_balance_entries` — PayPal 余额流水

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `account_id` | `bigint` NN | — | `FK → paypal_accounts.id`（级联） |
| `balance` | `numeric(14,2)` NN | — | 当次记录余额 |
| `entered_by` | `bigint` | — | 录入用户（bigint） |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE paypal_balance_entries_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE paypal_balance_entries (
    id           bigint          NOT NULL DEFAULT nextval('paypal_balance_entries_id_seq'),
    account_id   bigint          NOT NULL,
    balance      numeric(14,2)   NOT NULL,
    entered_by   bigint          NULL,
    created_at   timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at   timestamptz     NULL,

    CONSTRAINT paypal_balance_entries_pkey         PRIMARY KEY (id),
    CONSTRAINT paypal_balance_entries_account_fk   FOREIGN KEY (account_id) REFERENCES paypal_accounts(id) ON DELETE CASCADE
);
CREATE INDEX idx_paypal_balance_account_id ON paypal_balance_entries(account_id);
```

---

### 6.3 `paypal_reviews` — PayPal Review 历史

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `account_id` | `bigint` NN | — | `FK → paypal_accounts.id`（级联） |
| `review_count` | `integer` NN | — | 当次 Review 数量 |
| `entered_by` | `bigint` | — | 录入用户（bigint） |
| `created_at` | `timestamptz` NN | 当前时间 | 录入时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE paypal_reviews_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE paypal_reviews (
    id            bigint          NOT NULL DEFAULT nextval('paypal_reviews_id_seq'),
    account_id    bigint          NOT NULL,
    review_count  integer         NOT NULL,
    entered_by    bigint          NULL,
    created_at    timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at    timestamptz     NULL,

    CONSTRAINT paypal_reviews_pkey        PRIMARY KEY (id),
    CONSTRAINT paypal_reviews_account_fk  FOREIGN KEY (account_id) REFERENCES paypal_accounts(id) ON DELETE CASCADE
);
CREATE INDEX idx_paypal_reviews_account_id ON paypal_reviews(account_id);
```

---

### 6.4 `paypal_withdrawals` — PayPal 提现

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `account_id` | `bigint` NN | — | `FK → paypal_accounts.id`（级联） |
| `amount` | `numeric(14,2)` NN | — | 提现金额 |
| `source` | `text` | — | 提现来源/备注 |
| `withdrawn_at` | `date` NN | — | 提现业务日期 |
| `created_by` | `bigint` | — | 创建用户（bigint） |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE paypal_withdrawals_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE paypal_withdrawals (
    id            bigint          NOT NULL DEFAULT nextval('paypal_withdrawals_id_seq'),
    account_id    bigint          NOT NULL,
    amount        numeric(14,2)   NOT NULL,
    source        text            NULL,
    withdrawn_at  date            NOT NULL,
    created_by    bigint          NULL,
    created_at    timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at    timestamptz     NULL,

    CONSTRAINT paypal_withdrawals_pkey        PRIMARY KEY (id),
    CONSTRAINT paypal_withdrawals_account_fk  FOREIGN KEY (account_id) REFERENCES paypal_accounts(id) ON DELETE CASCADE
);
CREATE INDEX idx_paypal_withdrawals_account_id ON paypal_withdrawals(account_id);
```

---

## 7. Influencer 与站点

> 本章四张表保留 ERP 原貌。`influencer_domains` 不引入 `influencer_id` bigint FK（v1/v2 曾强加它导致数据 COPY 失败），仅保留 `influencer_name text` 列；`created_by_user_uuid` / `actor_user_uuid` / `order_uuid` 等跨域列**直接对接** `users.entity_uuid` 与 `orders.entity_uuid`（ERP 与 API 共用同一 UUID 命名空间）。

### 7.1 `influencers` — Influencer 主数据

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `display_name` | `text` NN | — | 显示名称 |
| `status` | `text` NN | `active` | 当前状态 |
| `profile` | `jsonb` NN | `{}` | 扩展资料 |
| `version` | `integer` NN | 1 | 乐观锁版本 |
| `created_by_user_uuid` | `uuid` | — | 创建用户，`FK → users.entity_uuid` |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE influencers (
    id                      uuid           NOT NULL DEFAULT gen_random_uuid(),
    display_name            text           NOT NULL,
    status                  text           NOT NULL DEFAULT 'active',
    profile                 jsonb          NOT NULL DEFAULT '{}'::jsonb,
    version                 integer        NOT NULL DEFAULT 1,
    created_by_user_uuid    uuid           NULL,
    created_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              timestamptz    NULL,

    CONSTRAINT influencers_pkey             PRIMARY KEY (id),
    CONSTRAINT influencers_creator_fk       FOREIGN KEY (created_by_user_uuid) REFERENCES users(entity_uuid) ON DELETE SET NULL
);
CREATE INDEX idx_influencers_display_name ON influencers(display_name);
```

---

### 7.2 `influencer_domains` — 域名 → Influencer 名称

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `domain` | `text` NN | — | 来源域名，唯一 |
| `influencer_name` | `text` | — | Influencer 名称（**保留 ERP 原貌，不引入 `influencer_id`**） |
| `confirmed` | `boolean` NN | `false` | 是否人工确认 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE influencer_domains_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE influencer_domains (
    id                bigint          NOT NULL DEFAULT nextval('influencer_domains_id_seq'),
    domain            text            NOT NULL,
    influencer_name   text            NULL,
    confirmed         boolean         NOT NULL DEFAULT false,
    created_at        timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at        timestamptz     NULL,

    CONSTRAINT influencer_domains_pkey          PRIMARY KEY (id),
    CONSTRAINT influencer_domains_domain_unique UNIQUE (domain)
);
CREATE INDEX idx_influencer_domains_domain       ON influencer_domains(domain);
CREATE INDEX idx_influencer_domains_influencer  ON influencer_domains(influencer_name) WHERE influencer_name IS NOT NULL;
```

---

### 7.3 `influencer_order_links` — Influencer × 订单

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `influencer_id` | `uuid` NN | — | `FK → influencers.id`（级联） |
| `order_uuid` | `uuid` NN | — | `FK → orders.entity_uuid`（级联） |
| `source` | `text` NN | `manual` | 关联来源 |
| `created_by_user_uuid` | `uuid` | — | 创建用户，`FK → users.entity_uuid` |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE influencer_order_links (
    id                      uuid           NOT NULL DEFAULT gen_random_uuid(),
    influencer_id           uuid           NOT NULL,
    order_uuid              uuid           NOT NULL,
    source                  text           NOT NULL DEFAULT 'manual',
    created_by_user_uuid    uuid           NULL,
    created_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              timestamptz    NULL,

    CONSTRAINT influencer_order_links_pkey          PRIMARY KEY (id),
    CONSTRAINT influencer_order_link_unique         UNIQUE (influencer_id, order_uuid),
    CONSTRAINT influencer_order_links_influencer_fk FOREIGN KEY (influencer_id)        REFERENCES influencers(id)     ON DELETE CASCADE,
    CONSTRAINT influencer_order_links_order_fk      FOREIGN KEY (order_uuid)           REFERENCES orders(entity_uuid) ON DELETE CASCADE,
    CONSTRAINT influencer_order_links_user_fk       FOREIGN KEY (created_by_user_uuid) REFERENCES users(entity_uuid)  ON DELETE SET NULL
);
```

---

### 7.4 `site_classification_reclassifications` — 站点归类重分类

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `release_id` | `text` NN | — | 发布/修复批次标识，PK 组成 |
| `order_id` | `text` NN | — | ERP 订单标识，PK 组成 |
| `source_domain` | `text` NN | — | 来源域名 |
| `previous_classification` | `text` NN | — | 重分类前归类 |
| `previous_influencer_name` | `text` | — | 重分类前 Influencer |
| `target_classification` | `text` NN | — | 重分类后归类 |
| `target_influencer_name` | `text` | — | 重分类后 Influencer |
| `created_at` | `timestamptz` NN | 当前时间 | 重分类记录时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE site_classification_reclassifications (
    release_id                  text           NOT NULL,
    order_id                    text           NOT NULL,
    source_domain               text           NOT NULL,
    previous_classification     text           NOT NULL,
    previous_influencer_name    text           NULL,
    target_classification       text           NOT NULL,
    target_influencer_name      text           NULL,
    created_at                  timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at                  timestamptz    NULL,

    CONSTRAINT site_classification_reclassifications_pkey PRIMARY KEY (release_id, order_id)
);
CREATE INDEX idx_site_classification_order_id ON site_classification_reclassifications(order_id);
```

---

## 8. 工作流 / 审计 / 附件

> 本章把 ERP 中剩下的"采购—仓库—物流 / 异步任务 / 工作流 / 运维" 9 张表集中列出，结构与 `sql.md` §6/§10/§11 + 数据字典 §6/§9/§10 完全一致。v4 在所有表上一并补齐 `created_at` / `updated_at` / `deleted_at`。

### 8.1 `attachments` — 多态附件

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 历史附件主键 |
| `entity_type` | `text` NN | — | 所属实体类型（多态标记） |
| `entity_id` | `bigint` | — | 历史实体主键 |
| `file_path` | `text` NN | — | 受控存储路径；敏感 |
| `mime` | `text` | — | MIME 类型 |
| `size_bytes` | `bigint` | — | 文件字节数 |
| `sha256` | `text` NN | — | 文件完整性摘要 |
| `entity_uuid` | `uuid` NN | `gen_random_uuid()` | 新域稳定附件 UUID，唯一 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE attachments_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE attachments (
    id           bigint          NOT NULL DEFAULT nextval('attachments_id_seq'),
    entity_type  text            NOT NULL,
    entity_id    bigint          NULL,
    file_path    text            NOT NULL,
    mime         text            NULL,
    size_bytes   bigint          NULL,
    sha256       text            NOT NULL,
    entity_uuid  uuid            NOT NULL DEFAULT gen_random_uuid(),
    created_at   timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at   timestamptz     NULL,

    CONSTRAINT attachments_pkey               PRIMARY KEY (id),
    CONSTRAINT attachments_sha256_unique     UNIQUE (sha256),
    CONSTRAINT attachments_entity_uuid_unique UNIQUE (entity_uuid)
);
CREATE INDEX idx_attachments_entity  ON attachments(entity_type, entity_id);
CREATE INDEX idx_attachments_sha256  ON attachments(sha256);
```

---

### 8.2 `workflow_events` — 工作流事件

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `entity_type` | `text` NN | — | 实体类型 |
| `entity_uuid` | `uuid` NN | — | 实体 UUID |
| `event_type` | `text` NN | — | 事件类型 |
| `from_state` | `text` | — | 迁移前状态 |
| `to_state` | `text` | — | 迁移后状态 |
| `actor_user_uuid` | `uuid` NN | — | `FK → users.entity_uuid` |
| `request_id` | `text` | — | 请求追踪标识 |
| `source` | `text` NN | `api` | 事件来源 |
| `before` | `jsonb` | — | 事件前快照 |
| `after` | `jsonb` | — | 事件后快照 |
| `created_at` | `timestamptz` NN | 当前时间 | 事件时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE workflow_events (
    id              uuid           NOT NULL DEFAULT gen_random_uuid(),
    entity_type     text           NOT NULL,
    entity_uuid     uuid           NOT NULL,
    event_type      text           NOT NULL,
    from_state      text           NULL,
    to_state        text           NULL,
    actor_user_uuid uuid           NOT NULL,
    request_id      text           NULL,
    source          text           NOT NULL DEFAULT 'api',
    before          jsonb          NULL,
    after           jsonb          NULL,
    created_at      timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      timestamptz    NULL,

    CONSTRAINT workflow_events_pkey           PRIMARY KEY (id),
    CONSTRAINT workflow_events_actor_fk       FOREIGN KEY (actor_user_uuid) REFERENCES users(entity_uuid) ON DELETE CASCADE
);
CREATE INDEX idx_workflow_entity_time ON workflow_events(entity_type, entity_uuid, created_at DESC);
CREATE INDEX idx_workflow_actor_time  ON workflow_events(actor_user_uuid, created_at DESC);
```

---

### 8.3 `operation_cases` — 人工复核 / Case

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `case_type` | `text` NN | — | Case 类型 |
| `status` | `text` NN | `open` | 当前状态 |
| `entity_type` | `text` | — | 关联实体类型 |
| `entity_uuid` | `uuid` | — | 关联实体 UUID |
| `summary` | `text` NN | — | 摘要 |
| `details` | `jsonb` NN | `{}` | 结构化详情 |
| `version` | `integer` NN | 1 | 乐观锁版本 |
| `owner_user_uuid` | `uuid` | — | 当前负责人，`FK → users.entity_uuid` |
| `created_by_user_uuid` | `uuid` NN | — | 创建用户，`FK → users.entity_uuid` |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间 |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE operation_cases (
    id                      uuid           NOT NULL DEFAULT gen_random_uuid(),
    case_type               text           NOT NULL,
    status                  text           NOT NULL DEFAULT 'open',
    entity_type             text           NULL,
    entity_uuid             uuid           NULL,
    summary                 text           NOT NULL,
    details                 jsonb          NOT NULL DEFAULT '{}'::jsonb,
    version                 integer        NOT NULL DEFAULT 1,
    owner_user_uuid         uuid           NULL,
    created_by_user_uuid    uuid           NOT NULL,
    created_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              timestamptz    NULL,

    CONSTRAINT operation_cases_pkey                 PRIMARY KEY (id),
    CONSTRAINT operation_cases_owner_fk             FOREIGN KEY (owner_user_uuid)      REFERENCES users(entity_uuid) ON DELETE SET NULL,
    CONSTRAINT operation_cases_creator_fk           FOREIGN KEY (created_by_user_uuid) REFERENCES users(entity_uuid) ON DELETE CASCADE
);
CREATE INDEX idx_operation_cases_status_updated ON operation_cases(status, updated_at DESC);
```

---

### 8.4 `idempotency_keys` — 写操作幂等控制

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `actor_user_uuid` | `uuid` NN | — | `FK → users.entity_uuid` |
| `scope` | `text` NN | — | 幂等作用域（UQ 组成） |
| `idempotency_key` | `text` NN | — | 客户端幂等键（UQ 组成） |
| `request_hash` | `text` NN | — | 请求内容摘要 |
| `state` | `text` NN | `processing` | `processing` / `completed`（CHECK） |
| `response_status` | `integer` | — | 已完成请求的 HTTP 状态 |
| `response_body` | `jsonb` | — | 已完成请求的响应快照 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `expires_at` | `timestamptz` NN | 当前时间+24h | 过期时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE idempotency_keys (
    id                uuid           NOT NULL DEFAULT gen_random_uuid(),
    actor_user_uuid   uuid           NOT NULL,
    scope             text           NOT NULL,
    idempotency_key   text           NOT NULL,
    request_hash      text           NOT NULL,
    state             text           NOT NULL DEFAULT 'processing',
    response_status   integer        NULL,
    response_body     jsonb          NULL,
    created_at        timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at        timestamptz    NOT NULL DEFAULT (now() + '24:00:00'),
    updated_at        timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at        timestamptz    NULL,

    CONSTRAINT idempotency_keys_pkey             PRIMARY KEY (id),
    CONSTRAINT idempotency_keys_actor_fk         FOREIGN KEY (actor_user_uuid) REFERENCES users(entity_uuid) ON DELETE CASCADE,
    CONSTRAINT idempotency_actor_scope_key_unique UNIQUE (actor_user_uuid, scope, idempotency_key),
    CONSTRAINT idempotency_state_check            CHECK (state IN ('processing', 'completed'))
);
CREATE INDEX idx_idempotency_expiry ON idempotency_keys(expires_at);
```

---

### 8.5 `background_jobs` — 异步任务

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `job_type` | `text` NN | — | `invoice_ocr` / `batch_export` / `report_refresh`（CHECK） |
| `status` | `text` NN | `queued` | `queued` / `processing` / `succeeded` / `failed` / `cancelled`（CHECK） |
| `progress_percent` | `integer` NN | 0 | 进度，0–100 |
| `queue_job_id` | `text` | — | 队列系统任务标识 |
| `input_attachment_uuid` | `uuid` | — | `FK → attachments.entity_uuid` |
| `input_sha256` | `text` | — | 输入文件 SHA-256 |
| `engine` | `text` | — | 处理引擎 |
| `model_version` | `text` | — | 模型版本 |
| `input` | `jsonb` NN | `{}` | 任务输入参数 |
| `result` | `jsonb` | — | 任务结果 |
| `error_code` | `text` | — | 失败错误码 |
| `error_message` | `text` | — | 脱敏错误信息 |
| `created_by_user_uuid` | `uuid` NN | — | `FK → users.entity_uuid` |
| `cancel_requested` | `boolean` NN | `false` | 是否请求取消 |
| `retry_count` | `integer` NN | 0 | 重试次数 |
| `started_at` | `timestamptz` | — | 开始执行时间 |
| `finished_at` | `timestamptz` | — | 结束时间 |
| `expires_at` | `timestamptz` NN | 当前时间+24h | 任务/结果到期时间 |
| `input_fingerprint` | `text` | — | 输入去重指纹 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间 |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE background_jobs (
    id                      uuid           NOT NULL DEFAULT gen_random_uuid(),
    job_type                text           NOT NULL,
    status                  text           NOT NULL DEFAULT 'queued',
    progress_percent        integer        NOT NULL DEFAULT 0,
    queue_job_id            text           NULL,
    input_attachment_uuid   uuid           NULL,
    input_sha256            text           NULL,
    engine                  text           NULL,
    model_version           text           NULL,
    input                   jsonb          NOT NULL DEFAULT '{}'::jsonb,
    result                  jsonb          NULL,
    error_code              text           NULL,
    error_message           text           NULL,
    created_by_user_uuid    uuid           NOT NULL,
    cancel_requested        boolean        NOT NULL DEFAULT false,
    retry_count             integer        NOT NULL DEFAULT 0,
    started_at              timestamptz    NULL,
    finished_at             timestamptz    NULL,
    expires_at              timestamptz    NOT NULL DEFAULT (now() + '24:00:00'),
    input_fingerprint       text           NULL,
    created_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              timestamptz    NULL,

    CONSTRAINT background_jobs_pkey              PRIMARY KEY (id),
    CONSTRAINT background_jobs_creator_fk        FOREIGN KEY (created_by_user_uuid)  REFERENCES users(entity_uuid)                ON DELETE CASCADE,
    CONSTRAINT background_jobs_attachment_fk     FOREIGN KEY (input_attachment_uuid) REFERENCES attachments(entity_uuid)       ON DELETE SET NULL,
    CONSTRAINT background_job_progress_check     CHECK (progress_percent >= 0 AND progress_percent <= 100),
    CONSTRAINT background_job_status_check       CHECK (status IN ('queued','processing','succeeded','failed','cancelled')),
    CONSTRAINT background_job_type_check         CHECK (job_type  IN ('invoice_ocr','batch_export','report_refresh'))
);
CREATE INDEX idx_background_jobs_status_created ON background_jobs(status, created_at DESC);
CREATE INDEX idx_background_jobs_expiry         ON background_jobs(expires_at) WHERE status <> 'succeeded';
CREATE INDEX idx_background_jobs_actor_hash     ON background_jobs(created_by_user_uuid, input_sha256);
```

---

### 8.6 `procurement_tasks`（历史采购）

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 历史采购任务主键 |
| `order_pk` | `bigint` | — | `FK → orders.id` |
| `order_id` | `text` | — | 订单标识快照 |
| `purchase_status` | `text` NN | `pending_purchase` | 状态，CHECK ∈ 历史六态 |
| `supplier` | `text` | — | 供应商 |
| `cost` | `numeric(14,2)` | — | 采购成本 |
| `eta` | `date` | — | 预计到达日 |
| `tracking_no` | `text` | — | 物流单号 |
| `notes` | `text` | — | 采购备注 |
| `created_by` | `bigint` | — | 创建用户（bigint） |
| `legacy_id` | `text` | — | 旧系统稳定标识，唯一 |
| `raw` | `jsonb` | — | 旧系统原始快照 |
| `entity_uuid` | `uuid` NN | `gen_random_uuid()` | 新域稳定 UUID，唯一 |
| `version` | `integer` NN | 1 | 乐观锁版本 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间 |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE procurement_tasks_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE procurement_tasks (
    id                 bigint          NOT NULL DEFAULT nextval('procurement_tasks_id_seq'),
    order_pk           bigint          NULL,
    order_id           text            NULL,
    purchase_status    text            NOT NULL DEFAULT 'pending_purchase',
    supplier           text            NULL,
    cost               numeric(14,2)   NULL,
    eta                date            NULL,
    tracking_no        text            NULL,
    notes              text            NULL,
    created_by         bigint          NULL,
    legacy_id          text            NULL,
    raw                jsonb           NULL,
    entity_uuid        uuid            NOT NULL DEFAULT gen_random_uuid(),
    version            integer         NOT NULL DEFAULT 1,
    created_at         timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at         timestamptz     NULL,

    CONSTRAINT procurement_tasks_pkey              PRIMARY KEY (id),
    CONSTRAINT procurement_tasks_entity_uuid_unique UNIQUE (entity_uuid),
    CONSTRAINT procurement_tasks_legacy_id_unique  UNIQUE (legacy_id),
    CONSTRAINT procurement_tasks_order_pk_fk       FOREIGN KEY (order_pk) REFERENCES orders(id),
    CONSTRAINT procurement_purchase_status_check   CHECK (
        purchase_status IN (
            'pending_purchase','supplier_shipping_pending','warehouse_arrived',
            'exchange_in_progress','return_in_progress',
            'customer_confirm_pending','shipped'
        )
    )
);
CREATE INDEX idx_proc_status       ON procurement_tasks(purchase_status);
CREATE INDEX idx_proc_entity_uuid  ON procurement_tasks(entity_uuid);
```

---

### 8.7 `procurement_removed_orders` — 历史移除留痕

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `legacy_id` | `text` NN | — | 旧系统稳定标识，唯一 |
| `order_id` | `text` | — | ERP 订单标识 |
| `paypal_order_id` | `text` | — | PayPal 订单标识 |
| `raw` | `jsonb` NN | — | 移除时快照 |
| `removed_by` | `bigint` | — | 执行移除用户（bigint） |
| `removed_at` | `timestamptz` NN | 当前时间 | 移除时间 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间（v4 新增） |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE procurement_removed_orders_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE procurement_removed_orders (
    id              bigint          NOT NULL DEFAULT nextval('procurement_removed_orders_id_seq'),
    legacy_id       text            NOT NULL,
    order_id        text            NULL,
    paypal_order_id text            NULL,
    raw             jsonb           NOT NULL,
    removed_by      bigint          NULL,
    removed_at      timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      timestamptz     NULL,

    CONSTRAINT procurement_removed_orders_pkey            PRIMARY KEY (id),
    CONSTRAINT procurement_removed_orders_legacy_id_unique UNIQUE (legacy_id)
);
```

---

### 8.8 `purchase_tasks` — 新采购任务

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `task_number` | `bigint` NN | 序列 | 可见任务编号，唯一 |
| `order_uuid` | `uuid` | — | `FK → orders.entity_uuid` |
| `legacy_procurement_id` | `bigint` | — | `FK → procurement_tasks.id` |
| `task_type` | `text` NN | — | `order_purchase` / `replacement` / `exchange` / `other`（CHECK） |
| `source` | `text` NN | — | `system` / `manual`（CHECK）；system 必须有 `order_uuid` |
| `status` | `text` NN | `pending_purchase` | 状态，CHECK ∈ 十态 |
| `supplier` | `text` | — | 供应商 |
| `purchase_cost` | `numeric(14,2)` | — | 采购成本 |
| `eta` | `date` | — | 预计到达日 |
| `tracking_number` | `text` | — | 物流单号 |
| `notes` | `text` | — | 备注 |
| `version` | `integer` NN | 1 | 乐观锁版本 |
| `created_by_user_uuid` | `uuid` NN | — | `FK → users.entity_uuid` |
| `updated_by_user_uuid` | `uuid` NN | — | `FK → users.entity_uuid` |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间 |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE saveb_purchase_task_number_seq START WITH 100000 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE purchase_tasks (
    id                          uuid           NOT NULL DEFAULT gen_random_uuid(),
    task_number                 bigint         NOT NULL DEFAULT nextval('saveb_purchase_task_number_seq'),
    order_uuid                  uuid           NULL,
    legacy_procurement_id       bigint         NULL,
    task_type                   text           NOT NULL,
    source                      text           NOT NULL,
    status                      text           NOT NULL DEFAULT 'pending_purchase',
    supplier                    text           NULL,
    purchase_cost               numeric(14,2)  NULL,
    eta                         date           NULL,
    tracking_number             text           NULL,
    notes                       text           NULL,
    version                     integer        NOT NULL DEFAULT 1,
    created_by_user_uuid        uuid           NOT NULL,
    updated_by_user_uuid        uuid           NOT NULL,
    created_at                  timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at                  timestamptz    NULL,

    CONSTRAINT purchase_tasks_pkey                PRIMARY KEY (id),
    CONSTRAINT purchase_tasks_task_number_unique  UNIQUE (task_number),
    CONSTRAINT purchase_tasks_order_uuid_unique   UNIQUE (order_uuid) WHERE order_uuid IS NOT NULL,
    CONSTRAINT purchase_tasks_order_uuid_fk       FOREIGN KEY (order_uuid)             REFERENCES orders(entity_uuid)         ON DELETE SET NULL,
    CONSTRAINT purchase_tasks_legacy_proc_fk      FOREIGN KEY (legacy_procurement_id)  REFERENCES procurement_tasks(id)      ON DELETE SET NULL,
    CONSTRAINT purchase_tasks_created_by_fk       FOREIGN KEY (created_by_user_uuid)   REFERENCES users(entity_uuid)        ON DELETE CASCADE,
    CONSTRAINT purchase_tasks_updated_by_fk       FOREIGN KEY (updated_by_user_uuid)   REFERENCES users(entity_uuid)        ON DELETE CASCADE,
    CONSTRAINT purchase_tasks_source_check        CHECK (source IN ('system','manual')),
    CONSTRAINT purchase_tasks_status_check        CHECK (status IN (
        'pending_purchase','waiting_supplier_shipment','arrived_warehouse',
        'inspection','shipped','exchange_pending','exchange_in_progress',
        'return_pending','return_in_progress','cancelled'
    )),
    CONSTRAINT purchase_tasks_type_check          CHECK (task_type IN ('order_purchase','replacement','exchange','other')),
    CONSTRAINT purchase_tasks_system_order_check  CHECK (source = 'manual' OR order_uuid IS NOT NULL)
);
CREATE INDEX idx_purchase_tasks_type_created    ON purchase_tasks(task_type, created_at DESC);
CREATE INDEX idx_purchase_tasks_status_updated  ON purchase_tasks(status, updated_at DESC);
CREATE INDEX idx_purchase_tasks_order_uuid      ON purchase_tasks(order_uuid);
CREATE INDEX idx_purchase_tasks_eta             ON purchase_tasks(eta) WHERE eta IS NOT NULL;
```

---

### 8.9 `purchase_task_items` — 采购任务商品明细

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `purchase_task_id` | `uuid` NN | — | `FK → purchase_tasks.id`（级联） |
| `order_item_id` | `uuid` | — | `FK → order_items.id` |
| `sku` | `text` | — | SKU |
| `product_name` | `text` NN | — | 商品名称 |
| `quantity` | `integer` NN | 1 | 数量 |
| `notes` | `text` | — | 备注 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE purchase_task_items (
    id                uuid           NOT NULL DEFAULT gen_random_uuid(),
    purchase_task_id  uuid           NOT NULL,
    order_item_id     uuid           NULL,
    sku               text           NULL,
    product_name      text           NOT NULL,
    quantity          integer        NOT NULL DEFAULT 1,
    notes             text           NULL,
    created_at        timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at        timestamptz    NULL,

    CONSTRAINT purchase_task_items_pkey             PRIMARY KEY (id),
    CONSTRAINT purchase_task_items_task_fk           FOREIGN KEY (purchase_task_id) REFERENCES purchase_tasks(id) ON DELETE CASCADE,
    CONSTRAINT purchase_task_items_order_item_fk     FOREIGN KEY (order_item_id)    REFERENCES order_items(id)    ON DELETE SET NULL
);
CREATE INDEX idx_purchase_task_items_task ON purchase_task_items(purchase_task_id);
```

---

### 8.10 `warehouse_records`（历史仓库履约）

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `procurement_task_id` | `bigint` NN | — | `FK → procurement_tasks.id`（级联），UQ |
| `fulfillment_status` | `text` | — | 履约状态 |
| `items` | `jsonb` NN | `[]` | 历史商品明细快照 |
| `history` | `jsonb` NN | `[]` | 历史状态轨迹 |
| `updated_by` | `bigint` | — | 最后修改用户 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间 |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE warehouse_records_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE warehouse_records (
    id                    bigint          NOT NULL DEFAULT nextval('warehouse_records_id_seq'),
    procurement_task_id   bigint          NOT NULL,
    fulfillment_status    text            NULL,
    items                 jsonb           NOT NULL DEFAULT '[]'::jsonb,
    history               jsonb           NOT NULL DEFAULT '[]'::jsonb,
    updated_by            bigint          NULL,
    created_at            timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at            timestamptz     NULL,

    CONSTRAINT warehouse_records_pkey            PRIMARY KEY (id),
    CONSTRAINT warehouse_procurement_unique      UNIQUE (procurement_task_id),
    CONSTRAINT warehouse_records_procurement_fk  FOREIGN KEY (procurement_task_id) REFERENCES procurement_tasks(id) ON DELETE CASCADE
);
```

---

### 8.11 `warehouse_receipts` — 新仓库收货

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `purchase_task_id` | `uuid` NN | — | `FK → purchase_tasks.id` |
| `receipt_number` | `text` NN | — | 收货单号，唯一 |
| `inspection_status` | `text` NN | `pending` | `pending` / `passed` / `failed` / `partial`（CHECK） |
| `received_items` | `jsonb` NN | `[]` | 实收商品结构化清单 |
| `received_by_user_uuid` | `uuid` NN | — | `FK → users.entity_uuid` |
| `received_at` | `timestamptz` NN | 当前时间 | 收货时间 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间（v4 新增） |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE warehouse_receipts (
    id                       uuid           NOT NULL DEFAULT gen_random_uuid(),
    purchase_task_id         uuid           NOT NULL,
    receipt_number           text           NOT NULL,
    inspection_status        text           NOT NULL DEFAULT 'pending',
    received_items           jsonb          NOT NULL DEFAULT '[]'::jsonb,
    received_by_user_uuid    uuid           NOT NULL,
    received_at              timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at               timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at               timestamptz    NULL,

    CONSTRAINT warehouse_receipts_pkey                 PRIMARY KEY (id),
    CONSTRAINT warehouse_receipts_receipt_number_unique UNIQUE (receipt_number),
    CONSTRAINT warehouse_receipts_task_fk              FOREIGN KEY (purchase_task_id)      REFERENCES purchase_tasks(id) ON DELETE CASCADE,
    CONSTRAINT warehouse_receipts_user_fk              FOREIGN KEY (received_by_user_uuid) REFERENCES users(entity_uuid) ON DELETE CASCADE,
    CONSTRAINT warehouse_receipt_inspection_check      CHECK (inspection_status IN ('pending','passed','failed','partial'))
);
CREATE INDEX idx_warehouse_receipt_task_time ON warehouse_receipts(purchase_task_id, received_at DESC);
```

---

### 8.12 `warehouse_shipments` — 新仓库发货

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `uuid` NN | `gen_random_uuid()` | 主键 |
| `purchase_task_id` | `uuid` NN | — | `FK → purchase_tasks.id` |
| `shipment_number` | `text` NN | — | 发货单号，唯一 |
| `carrier` | `text` | — | 承运商 |
| `tracking_number` | `text` | — | 物流单号 |
| `shipped_items` | `jsonb` NN | `[]` | 发货商品结构化清单 |
| `shipped_by_user_uuid` | `uuid` NN | — | `FK → users.entity_uuid` |
| `shipped_at` | `timestamptz` NN | 当前时间 | 发货时间 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间（v4 新增） |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE warehouse_shipments (
    id                       uuid           NOT NULL DEFAULT gen_random_uuid(),
    purchase_task_id         uuid           NOT NULL,
    shipment_number          text           NOT NULL,
    carrier                  text           NULL,
    tracking_number          text           NULL,
    shipped_items            jsonb          NOT NULL DEFAULT '[]'::jsonb,
    shipped_by_user_uuid     uuid           NOT NULL,
    shipped_at               timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at               timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at               timestamptz    NULL,

    CONSTRAINT warehouse_shipments_pkey                PRIMARY KEY (id),
    CONSTRAINT warehouse_shipments_shipment_number_unique UNIQUE (shipment_number),
    CONSTRAINT warehouse_shipments_task_fk             FOREIGN KEY (purchase_task_id)       REFERENCES purchase_tasks(id) ON DELETE CASCADE,
    CONSTRAINT warehouse_shipments_user_fk             FOREIGN KEY (shipped_by_user_uuid)  REFERENCES users(entity_uuid) ON DELETE CASCADE
);
CREATE INDEX idx_warehouse_shipment_task_time ON warehouse_shipments(purchase_task_id, shipped_at DESC);
```

---

### 8.13 `shipment_tracking_events` — 物流事件

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `procurement_task_id` | `bigint` | — | `FK → procurement_tasks.id`（级联） |
| `provider` | `text` NN | — | 物流数据提供方（UQ 组成） |
| `tracking_number` | `text` NN | — | 物流单号 |
| `status` | `text` | — | 物流主状态 |
| `substatus` | `text` | — | 物流子状态 |
| `event_id` | `text` | — | 提供方事件标识（UQ 组成） |
| `raw` | `jsonb` NN | `{}` | 提供方原始事件快照 |
| `occurred_at` | `timestamptz` | — | 事件发生时间 |
| `created_at` | `timestamptz` NN | 当前时间 | 入库时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE shipment_tracking_events_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE shipment_tracking_events (
    id                    bigint          NOT NULL DEFAULT nextval('shipment_tracking_events_id_seq'),
    procurement_task_id   bigint          NULL,
    provider              text            NOT NULL,
    tracking_number       text            NOT NULL,
    status                text            NULL,
    substatus             text            NULL,
    event_id              text            NULL,
    raw                   jsonb           NOT NULL DEFAULT '{}'::jsonb,
    occurred_at           timestamptz     NULL,
    created_at            timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at            timestamptz     NULL,

    CONSTRAINT shipment_tracking_events_pkey            PRIMARY KEY (id),
    CONSTRAINT tracking_provider_event_unique           UNIQUE (provider, tracking_number, event_id),
    CONSTRAINT shipment_tracking_events_procurement_fk  FOREIGN KEY (procurement_task_id) REFERENCES procurement_tasks(id) ON DELETE CASCADE
);
CREATE INDEX idx_tracking_number_time ON shipment_tracking_events(tracking_number, occurred_at DESC);
```

---

## 9. 系统与兼容

> 本章三张系统表（`system_state` / `legacy_dashboard_days` / `legacy_import_items`）结构与 `sql.md` §11 + 数据字典 §10 完全一致。v4 一并补 `created_at` / `updated_at` / `deleted_at`。

### 9.1 `system_state` — 分布式键值状态

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `key` | `text` NN | — | 状态键，主键 |
| `value` | `jsonb` NN | `{}` | 状态值 |
| `updated_at` | `timestamptz` NN | 当前时间 | 最近更新时间 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE system_state (
    key         text          NOT NULL,
    value       jsonb         NOT NULL DEFAULT '{}'::jsonb,
    updated_at  timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at  timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at  timestamptz   NULL,

    CONSTRAINT system_state_pkey PRIMARY KEY (key)
);
```

---

### 9.2 `legacy_dashboard_days` — 旧版 Dashboard 日快照

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `day` | `date` NN | — | 快照业务日，主键 |
| `payload` | `jsonb` NN | — | 旧版 Dashboard 日数据 |
| `source_sha256` | `varchar(64)` NN | — | 来源文件 SHA-256 |
| `source_size_bytes` | `bigint` NN | — | 来源文件字节数 |
| `snapshot_cutoff_asia_shanghai` | `text` NN | — | Asia/Shanghai 快照截止时间描述 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间 |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间 |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE TABLE legacy_dashboard_days (
    day                              date          NOT NULL,
    payload                          jsonb         NOT NULL,
    source_sha256                    varchar(64)   NOT NULL,
    source_size_bytes                bigint        NOT NULL,
    snapshot_cutoff_asia_shanghai    text          NOT NULL,
    created_at                       timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                       timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at                       timestamptz   NULL,

    CONSTRAINT legacy_dashboard_days_pkey PRIMARY KEY (day)
);
CREATE INDEX idx_legacy_dashboard_days_updated ON legacy_dashboard_days(updated_at DESC);
```

---

### 9.3 `legacy_import_items` — 旧数据导入幂等清单

| 字段 | 类型/可空 | 默认 | 说明 |
|---|---|---|---|
| `id` | `bigserial` NN | 序列 | 主键 |
| `source_key` | `text` NN | — | 来源数据稳定键，唯一 |
| `source_sha256` | `text` NN | — | 来源内容摘要 |
| `entity_type` | `text` NN | — | 导入目标实体类型 |
| `entity_id` | `text` | — | 导入目标实体标识 |
| `imported_at` | `timestamptz` NN | 当前时间 | 导入时间 |
| `created_at` | `timestamptz` NN | 当前时间 | 创建时间（v4 新增） |
| `updated_at` | `timestamptz` NN | 当前时间 | 更新时间（v4 新增） |
| `deleted_at` | `timestamptz` NULL | — | 软删除（v4 新增） |

```sql
CREATE SEQUENCE legacy_import_items_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE TABLE legacy_import_items (
    id              bigint          NOT NULL DEFAULT nextval('legacy_import_items_id_seq'),
    source_key      text            NOT NULL,
    source_sha256   text            NOT NULL,
    entity_type     text            NOT NULL,
    entity_id       text            NULL,
    imported_at     timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      timestamptz     NULL,

    CONSTRAINT legacy_import_items_pkey             PRIMARY KEY (id),
    CONSTRAINT legacy_import_items_source_key_unique UNIQUE (source_key)
);
```

---

## 10. 迁移经验记录（v1/v2 → v3）

### 10.1 v1 失败模式（`copy-12tables-data.ps1`）

- 早期版本使用 PG/PL 拼装器，输出时撞到 `record type has not been registered` 报错。
- `influencer_domains` 表的 `influencer_id` NOT NULL FK 引发 COPY NULL 拒绝（v3 已修）。

### 10.2 v2 失败模式（`copy-12tables-data-v2.ps1`）

- 改成 `\copy ... TO STDOUT` 后，多数表同步成功；
- `influencer_domains` 因 `influencer_id` 被 v2 migration 强设为 NOT NULL bigint FK，COPY 阶段 `null value in column "influencer_id" violates not-null constraint` 直接抛出。

### 10.3 v3 解决路径

- **修改 `influencer_domains` schema**：去掉 `influencer_id` bigint NOT NULL FK，回归 ERP 原貌 `influencer_name text`；
- **统一迁移策略**：保留原 ERP 字段原貌（含 UUID 主键、UUID 外键、原数值精度）；
- **不再做跨主键体系转换**：避免两套体系对不上的隐性失败；
- **数据迁移脚本**：`copy-data-v3.ps1` 用原生 `\copy TO STDOUT CSV HEADER` 出口从 ERP 取数据，再用 `COPY FROM ... CSV HEADER` 落入 API；保留 UTF-8；保留三个时间戳列的默认值语义。

### 10.4 v4 修正：保留 ERP 原貌 + 仅补三个时间戳列

| 项目 | 旧策略（v3） | 新策略（v4，本文档） |
|---|---|---|
| `users` | RBAC bigint 主表 | RBAC 大表 + 增列 `entity_uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid()`，并给历史用户回填 |
| `influencer_domains` | 删 `influencer_id` FK | 同 v3（保留 `influencer_name text`，不引入 bigint FK） |
| `paypal_balance_entries` 字段名 | `entered_by` | `entered_by`（保留 ERP 字段名） |
| `paypal_withdrawals` 字段名 | `created_by` | `created_by`（保留 ERP 字段名） |
| `invoice_orders` 字段名 | `created_by`, `invoice_screenshot_attachment_id` | 同 v3 |
| `timestamptz` 精度 | `timestamptz(6)` | `timestamptz`（与 ERP 完全一致；PG 内部等价） |
| 类型映射 | 部分用 `numeric` 简化 | 严格保留 ERP `numeric(14,2)` / `numeric(18,10)` / `numeric(5,2)` / `numeric(6,4)` 等 |
| 跨域 UUID FK | 通过 `users.id` 桥接 | 直接对 `users.entity_uuid` / `orders.entity_uuid`（ERP 与 API 共用命名空间） |
| CHECK 约束 | 简化或省略 | 全部按 ERP（status / type / classification / share_ratio 等） |
| 时间戳列 | 部分表漏 `updated_at` 或 `deleted_at` | 统一每张业务表三个时间戳列齐备（`created_at` / `updated_at` / `deleted_at`） |

### 10.5 列名映射（v3 收敛 → v4 不再 rename）

v3 起 API 表与 ERP 字段名完全对齐，**不再 rename**。下表仅作历史对照：

| 表 | v2 API 字段名（原意） | v3 / v4 字段名（与 ERP 一致） |
|---|---|---|
| `paypal_balance_entries` | `entered_by_user_id` | `entered_by` |
| `paypal_reviews` | `entered_by_user_id` | `entered_by` |
| `paypal_withdrawals` | `created_by_user_id` | `created_by` |
| `invoice_orders` | `created_by_user_id`, `attachment_id` | `created_by`, `invoice_screenshot_attachment_id` |
| `invoice_items` | `image_attachment_id` | `image_attachment_id`（不变） |
| `influencer_domains` | `influencer_id` (bigint NOT NULL) | `influencer_name` (text NULL) |

### 10.6 数据回填范围（v4）

v4 在"`2026_09_07_*_create_business_tables_v4_consolidated.php` + `2026_09_07_*_copy_business_data_v4.ps1`"中按以下顺序回填基础业务数据：

1. `users.entity_uuid` 老用户回填 `gen_random_uuid()`
2. `paypal_accounts / paypal_balance_entries / paypal_reviews / paypal_withdrawals`（无跨域 UUID FK）
3. `influencers / influencer_domains`
4. `orders / order_items / order_user_overrides / order_staff_allocations / pending_completion_operations / order_status_observations / order_staff_performance_projection`
5. `invoice_orders / invoice_items / invoice_adjustments / invoice_staff_allocations / invoice_operation_logs`
6. `procurement_tasks / purchase_tasks / purchase_task_items / warehouse_records / warehouse_receipts / warehouse_shipments / shipment_tracking_events`
7. `attachments / workflow_events / operation_cases / idempotency_keys / background_jobs`
8. `daily_stats / exchange_rates / system_state / legacy_dashboard_days / legacy_import_items`

ERP 端为 0 行的表（`order_items`、`invoice_operation_logs`、`pending_completion_operations`、`order_status_observations`、`order_staff_performance_projection` 等）**只建表，不回填**。后续如果有需要可单独跑"按 uuid 拷贝"的脚本。
