# Saveb ERP Database Schema

> PostgreSQL 16 | Source: `erp_backup.sql` (erp-sync/) + Laravel migrations

## Table of Contents

1. [Schema Overview](#1-schema-overview)
2. [Users & Auth](#2-users--auth)
3. [RBAC (New)](#3-rbac-new)
4. [Orders & Order Items](#4-orders--order-items)
5. [Procurement & Purchase](#5-procurement--purchase)
6. [Warehouse](#6-warehouse)
7. [Invoices](#7-invoices)
8. [PayPal / Finance](#8-paypal--finance)
9. [Influencers](#9-influencers)
10. [Workflow & Audit](#10-workflow--audit)
11. [System & Utilities](#11-system--utilities)

---

## 1. Schema Overview

| Category | Tables |
|---|---|
| Users & Auth | `users`, `api_tokens` |
| RBAC (New) | `roles`, `menus`, `permissions`, `role_menus`, `role_permissions` |
| Orders | `orders`, `order_items`, `order_user_overrides`, `order_staff_allocations`, `order_staff_performance_projection`, `order_status_observations` |
| Procurement | `procurement_tasks`, `procurement_removed_orders` |
| Purchase | `purchase_tasks`, `purchase_task_items` |
| Warehouse | `warehouse_receipts`, `warehouse_records`, `warehouse_shipments` |
| Invoices | `invoice_orders`, `invoice_items`, `invoice_adjustments`, `invoice_staff_allocations`, `invoice_operation_logs` |
| Finance | `paypal_accounts`, `paypal_balance_entries`, `paypal_reviews`, `paypal_withdrawals` |
| Influencers | `influencers`, `influencer_domains`, `influencer_order_links` |
| Operations | `operation_cases` |
| Workflow | `workflow_events`, `pending_completion_operations` |
| Audit | `audit_logs` |
| System | `attachments`, `background_jobs`, `idempotency_keys`, `daily_stats`, `exchange_rates`, `site_classification_reclassifications`, `shipment_tracking_events`, `system_state`, `legacy_dashboard_days`, `legacy_import_items` |

---

## 2. Users & Auth

### users

The central user table. Users belong to one of the predefined roles via a CHECK constraint.

```sql
CREATE TABLE public.users (
    id                          bigint        NOT NULL DEFAULT nextval('users_id_seq'),
    username                    text          NOT NULL,
    password_hash              text          NOT NULL,
    display_name               text          NOT NULL,
    role                       text          NOT NULL,
    staff_code                 text,
    active                     boolean       NOT NULL DEFAULT true,
    created_at                 timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    must_change_password       boolean       NOT NULL DEFAULT false,
    entity_uuid                uuid          NOT NULL DEFAULT gen_random_uuid(),

    CONSTRAINT users_pkey                      PRIMARY KEY (id),
    CONSTRAINT users_entity_uuid_unique         UNIQUE (entity_uuid),
    CONSTRAINT users_username_unique            UNIQUE (username),
    CONSTRAINT users_role_check                 CHECK (
        role IN ('admin','finance','cs','customer_service','customer_service_client',
                 'purchasing','warehouse','influencer','operations','viewer')
    )
);

-- Sequence
CREATE SEQUENCE public.users_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE users_id_seq OWNED BY users.id;
```

**Indexes:**
```sql
-- Primary key (id)
-- Unique: entity_uuid, username
```

---

### api_tokens

Bearer tokens issued to users for API authentication. The plain-text token is only returned once on creation; only its SHA-256 hash is stored.

```sql
CREATE TABLE public.api_tokens (
    id              bigint       NOT NULL DEFAULT nextval('api_tokens_id_seq'),
    user_id         bigint       NOT NULL,
    token_hash      varchar(64)  NOT NULL,
    name            varchar(100) NULL,           -- short device/app label (≤100 chars)
    abilities       jsonb        NULL,           -- ['*'] or ['read','write']
    ip              varchar(45) NULL,
    user_agent      varchar(500) NULL,
    last_used_at    timestamptz  NULL,
    expires_at      timestamptz  NULL,
    created_at      timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT api_tokens_pkey           PRIMARY KEY (id),
    CONSTRAINT api_tokens_token_hash_unique UNIQUE (token_hash),
    CONSTRAINT api_tokens_user_id_fkey   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE SEQUENCE public.api_tokens_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE api_tokens_id_seq OWNED BY api_tokens.id;

-- Indexes
CREATE INDEX idx_api_tokens_user_id   ON api_tokens(user_id);
CREATE INDEX idx_api_tokens_expires   ON api_tokens(expires_at);
```

---

## 3. RBAC (New)

> Replaces the legacy `roles`, `permissions`, `role_permissions`, `user_roles` tables. The new RBAC system uses integer primary keys and supports a 3-level menu tree.

### roles

```sql
CREATE TABLE public.roles (
    id          bigserial      NOT NULL,
    code        varchar(64)    NOT NULL,
    name        varchar(100)   NOT NULL,
    description varchar(255)   NULL,
    status      smallint       NOT NULL DEFAULT 1,  -- 1=active, 0=disabled
    is_system   boolean        NOT NULL DEFAULT false,
    sort        integer        NOT NULL DEFAULT 0,
    created_at  timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at  timestamptz    NULL,

    CONSTRAINT roles_pkey          PRIMARY KEY (id),
    CONSTRAINT roles_code_unique   UNIQUE (code)
);

CREATE INDEX idx_roles_status_sort ON roles(status, sort);
```

**Default records:**
```sql
INSERT INTO roles (id, code, name, description, status, is_system, sort) VALUES
(1, 'super_admin', 'Super Administrator', 'Has every menu and permission. Cannot be deleted.', 1, true,  0),
(2, 'admin',       'Administrator',       'Default administrative role.',                    1, true, 10),
(3, 'viewer',      'Read-only Viewer',    'Can only view resources, no write access.',         1, true, 90);
```

---

### menus

Three-level menu tree. `level` = 1 (module) → 2 (page) → 3 (button/action). `parent_id = 0` denotes a root-level-1 menu.

```sql
CREATE TABLE public.menus (
    id          bigserial      NOT NULL,
    parent_id   bigint        NOT NULL DEFAULT 0,
    code        varchar(64)   NOT NULL,
    name        varchar(100)  NOT NULL,
    path        varchar(255)  NULL,           -- frontend route path
    icon        varchar(64)   NULL,
    level       smallint      NOT NULL DEFAULT 1,  -- 1=module, 2=page, 3=button
    type        varchar(16)   NOT NULL DEFAULT 'menu',  -- 'menu' | 'action'
    component   varchar(255) NULL,           -- Vue component path
    sort        integer       NOT NULL DEFAULT 0,
    status      smallint      NOT NULL DEFAULT 1,
    hidden      boolean       NOT NULL DEFAULT false,
    created_at  timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at  timestamptz   NULL,

    CONSTRAINT menus_pkey      PRIMARY KEY (id),
    CONSTRAINT menus_code_unique UNIQUE (code)
);

CREATE INDEX idx_menus_parent_level_sort ON menus(parent_id, level, sort);
CREATE INDEX idx_menus_status             ON menus(status);
```

---

### permissions

Permission codes attached to menus. Each code represents a specific action (e.g. `user.create`).

```sql
CREATE TABLE public.permissions (
    id          bigserial      NOT NULL,
    menu_id     bigint        NULL,
    code        varchar(100)  NOT NULL,
    name        varchar(100)  NOT NULL,
    action      varchar(32)   NOT NULL DEFAULT 'custom',
                    -- 'list' | 'create' | 'update' | 'delete' | 'export' | 'custom'
    resource    varchar(100)  NULL,
    description varchar(255)  NULL,
    sort        integer       NOT NULL DEFAULT 0,
    status      smallint      NOT NULL DEFAULT 1,
    created_at  timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at  timestamptz   NULL,

    CONSTRAINT permissions_pkey            PRIMARY KEY (id),
    CONSTRAINT permissions_code_unique      UNIQUE (code),
    CONSTRAINT permissions_menu_id_fkey    FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE SET NULL
);

CREATE INDEX idx_permissions_menu_status ON permissions(menu_id, status);
```

---

### role_menus

Many-to-many pivot between roles and menus.

```sql
CREATE TABLE public.role_menus (
    role_id     bigint  NOT NULL,
    menu_id     bigint  NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT role_menus_pkey            PRIMARY KEY (role_id, menu_id),
    CONSTRAINT role_menus_role_id_fkey    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT role_menus_menu_id_fkey    FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE
);

CREATE INDEX idx_role_menus_menu_id ON role_menus(menu_id);
```

---

### role_permissions

Many-to-many pivot between roles and permissions.

```sql
CREATE TABLE public.role_permissions (
    role_id         bigint  NOT NULL,
    permission_id   bigint  NOT NULL,
    created_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT role_permissions_pkey            PRIMARY KEY (role_id, permission_id),
    CONSTRAINT role_permissions_role_id_fkey   FOREIGN KEY (role_id)      REFERENCES roles(id)       ON DELETE CASCADE,
    CONSTRAINT role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

CREATE INDEX idx_role_permissions_permission_id ON role_permissions(permission_id);
```

---

## 4. Orders & Order Items

### orders

Central order table. One order may have many `order_items`. References `users.entity_uuid` via `staff_code`.

```sql
CREATE TABLE public.orders (
    id                  bigserial    NOT NULL,
    order_id            text         NOT NULL,
    paypal_order_id     text,
    order_time          timestamptz,
    customer_name       text,
    source_site         text,
    classification      text,
    influencer_name     text,
    receiving_paypal     text,
    amount_original     numeric(14,2),
    currency            text,
    amount_usd          numeric(14,2),
    items_count         integer      NOT NULL DEFAULT 1,
    product_name        text,
    order_status        text,
    staff_code          text,
    raw                 jsonb        NOT NULL DEFAULT '{}',
    created_at          timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    client_order_id     text,
    entity_uuid         uuid         NOT NULL DEFAULT gen_random_uuid(),
    visible_order_id    bigint       NOT NULL DEFAULT nextval('saveb_visible_order_id_seq'),
    version             integer      NOT NULL DEFAULT 1,

    CONSTRAINT orders_pkey                  PRIMARY KEY (id),
    CONSTRAINT orders_entity_uuid_unique     UNIQUE (entity_uuid),
    CONSTRAINT orders_order_id_unique        UNIQUE (order_id),
    CONSTRAINT orders_visible_order_id_unique UNIQUE (visible_order_id),
    CONSTRAINT orders_client_order_id_unique UNIQUE (client_order_id)
);

CREATE SEQUENCE public.saveb_visible_order_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

-- Indexes
CREATE INDEX idx_orders_time     ON orders(order_time);
CREATE INDEX idx_orders_status   ON orders(order_status);
CREATE INDEX idx_orders_staff   ON orders(staff_code);
CREATE INDEX idx_orders_paypal  ON orders(paypal_order_id);
CREATE INDEX idx_orders_client_order_id ON orders(client_order_id);
CREATE INDEX idx_orders_class   ON orders(classification);
CREATE INDEX idx_orders_entity_uuid ON orders(entity_uuid);
```

---

### order_items

Line items belonging to an order.

```sql
CREATE TABLE public.order_items (
    id              uuid      NOT NULL DEFAULT gen_random_uuid(),
    order_uuid      uuid      NOT NULL,
    sku             text,
    product_name    text      NOT NULL,
    quantity        integer   NOT NULL DEFAULT 1,
    unit_price      numeric(14,2),
    currency        text,
    metadata        jsonb     NOT NULL DEFAULT '{}',
    created_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT order_items_pkey PRIMARY KEY (id)
);

CREATE INDEX idx_order_items_order_uuid ON order_items(order_uuid);
```

---

### order_user_overrides

Staff assignment override for completed orders. Allows reassigning commission.

```sql
CREATE TABLE public.order_user_overrides (
    id                  uuid      NOT NULL DEFAULT gen_random_uuid(),
    order_key           text      NOT NULL,
    order_key_type      text      NOT NULL,
    order_uuid          uuid,
    source_status       text      NOT NULL,
    status_override     text      NOT NULL,
    primary_staff_code  text      NOT NULL,
    version             integer   NOT NULL DEFAULT 1,
    updated_by_user_uuid uuid      NOT NULL,
    created_at          timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT order_user_overrides_pkey           PRIMARY KEY (id),
    CONSTRAINT order_user_override_identity_unique UNIQUE (order_key, order_key_type),
    CONSTRAINT order_user_override_completed_only  CHECK (status_override = 'completed'),
    CONSTRAINT order_user_override_key_type_check  CHECK (
        order_key_type IN ('client', 'order', 'paypal')
    ),
    CONSTRAINT order_user_override_version_positive CHECK (version > 0)
);

CREATE INDEX idx_order_user_overrides_order_uuid  ON order_user_overrides(order_uuid);
CREATE INDEX idx_order_user_overrides_updated_at ON order_user_overrides(updated_at);
```

---

### order_staff_allocations

Staff allocation per order override, with commission share ratio.

```sql
CREATE TABLE public.order_staff_allocations (
    id                 uuid        NOT NULL DEFAULT gen_random_uuid(),
    order_override_id   uuid        NOT NULL,
    staff_code         text        NOT NULL,
    participant_role   text        NOT NULL,
    share_ratio        numeric(12,10) NOT NULL,
    created_at         timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT order_staff_allocations_pkey            PRIMARY KEY (id),
    CONSTRAINT order_staff_allocation_unique           UNIQUE (order_override_id, staff_code),
    CONSTRAINT order_staff_allocation_role_check       CHECK (participant_role IN ('primary', 'collaborator')),
    CONSTRAINT order_staff_allocation_share_check      CHECK (share_ratio > 0 AND share_ratio <= 1)
);

CREATE INDEX idx_order_staff_allocations_staff ON order_staff_allocations(staff_code);
```

---

### order_staff_performance_projection

Monthly staff performance commission projection per order.

```sql
CREATE TABLE public.order_staff_performance_projection (
    id                  bigserial     NOT NULL,
    operation_uuid      uuid          NOT NULL,
    order_uuid          uuid          NOT NULL,
    business_date       date          NOT NULL,
    staff_code         text          NOT NULL,
    share_ratio         numeric(12,10) NOT NULL,
    orders_basis        numeric(18,10) NOT NULL,
    items_basis         numeric(18,10) NOT NULL,
    amount_usd_basis    numeric(18,10) NOT NULL,
    commission_percent  numeric(12,6),
    created_at          timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT order_staff_performance_projection_pkey PRIMARY KEY (id),
    CONSTRAINT order_staff_performance_operation_staff_unique UNIQUE (operation_uuid, staff_code),
    CONSTRAINT order_staff_performance_share_check   CHECK (share_ratio > 0 AND share_ratio <= 1),
    CONSTRAINT order_staff_performance_commission_nonnegative CHECK (
        commission_percent IS NULL OR commission_percent >= 0
    )
);

CREATE SEQUENCE public.order_staff_performance_projection_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE order_staff_performance_projection_id_seq OWNED BY order_staff_performance_projection.id;

CREATE INDEX idx_order_staff_performance_month_staff ON order_staff_performance_projection(business_date, staff_code);
```

---

### order_status_observations

Stores denormalized order status timeline for dashboard and analytics.

```sql
CREATE TABLE public.order_status_observations (
    id                       bigserial   NOT NULL,
    order_uuid               uuid        NOT NULL,
    identity_type            text        NOT NULL,
    identity_key            text        NOT NULL,
    source_system           text        NOT NULL DEFAULT 'saveb_erp',
    order_source_stable_key text        NOT NULL,
    status                  text        NOT NULL,
    normalized_status       text        NOT NULL,
    classification          text,
    source_business_time    timestamptz,
    observed_at             timestamptz  NOT NULL,
    source                  text        NOT NULL,
    operation_uuid          uuid        NOT NULL,
    bounded_projection      jsonb        NOT NULL DEFAULT '{}',
    created_at              timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT order_status_observations_pkey           PRIMARY KEY (id),
    CONSTRAINT order_status_observation_operation_unique UNIQUE (operation_uuid)
);

CREATE INDEX idx_order_status_observation_stable_timeline ON order_status_observations(order_source_stable_key, observed_at DESC);
CREATE INDEX idx_order_status_observation_business_status ON order_status_observations(normalized_status, observed_at DESC);
```

---

## 5. Procurement & Purchase

### procurement_tasks (Legacy)

Replaced by `purchase_tasks`. Kept for historical data.

```sql
CREATE TABLE public.procurement_tasks (
    id              bigserial     NOT NULL,
    order_pk        bigint,
    order_id        text,
    purchase_status text          NOT NULL DEFAULT 'pending_purchase',
    supplier        text,
    cost            numeric(14,2),
    eta             date,
    tracking_no     text,
    notes           text,
    created_by      bigint,
    created_at      timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    legacy_id       text,
    raw             jsonb,
    entity_uuid     uuid          NOT NULL DEFAULT gen_random_uuid(),
    version         integer       NOT NULL DEFAULT 1,

    CONSTRAINT procurement_tasks_pkey          PRIMARY KEY (id),
    CONSTRAINT procurement_tasks_entity_uuid_unique UNIQUE (entity_uuid),
    CONSTRAINT procurement_tasks_legacy_id_unique  UNIQUE (legacy_id),
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

### procurement_removed_orders

Orders removed from the procurement pipeline.

```sql
CREATE TABLE public.procurement_removed_orders (
    id          bigserial  NOT NULL,
    legacy_id   text       NOT NULL,
    order_id    text,
    paypal_order_id text,
    raw         jsonb      NOT NULL,
    removed_by  bigint,
    removed_at  timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT procurement_removed_orders_pkey       PRIMARY KEY (id),
    CONSTRAINT procurement_removed_orders_legacy_id_unique UNIQUE (legacy_id)
);
```

---

### purchase_tasks

Modern procurement/purchase task system.

```sql
CREATE TABLE public.purchase_tasks (
    id                      uuid        NOT NULL DEFAULT gen_random_uuid(),
    task_number             bigint      NOT NULL DEFAULT nextval('saveb_purchase_task_number_seq'),
    order_uuid              uuid,
    legacy_procurement_id   bigint,
    task_type              text        NOT NULL,
    source                 text        NOT NULL,
    status                 text        NOT NULL DEFAULT 'pending_purchase',
    supplier               text,
    purchase_cost          numeric(14,2),
    eta                    date,
    tracking_number        text,
    notes                  text,
    version                integer      NOT NULL DEFAULT 1,
    created_by_user_uuid    uuid        NOT NULL,
    updated_by_user_uuid    uuid        NOT NULL,
    created_at             timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT purchase_tasks_pkey               PRIMARY KEY (id),
    CONSTRAINT purchase_tasks_task_number_unique UNIQUE (task_number),
    CONSTRAINT purchase_tasks_entity_uuid_unique  UNIQUE (order_uuid) WHERE order_uuid IS NOT NULL,
    CONSTRAINT purchase_tasks_source_check        CHECK (source IN ('system', 'manual')),
    CONSTRAINT purchase_tasks_status_check        CHECK (
        status IN (
            'pending_purchase','waiting_supplier_shipment','arrived_warehouse',
            'inspection','shipped','exchange_pending','exchange_in_progress',
            'return_pending','return_in_progress','cancelled'
        )
    ),
    CONSTRAINT purchase_tasks_type_check          CHECK (
        task_type IN ('order_purchase', 'replacement', 'exchange', 'other')
    ),
    CONSTRAINT purchase_tasks_system_order_check  CHECK (
        source = 'manual' OR order_uuid IS NOT NULL
    )
);

CREATE SEQUENCE public.saveb_purchase_task_number_seq START WITH 100000 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE INDEX idx_purchase_tasks_type_created    ON purchase_tasks(task_type, created_at DESC);
CREATE INDEX idx_purchase_tasks_status_updated  ON purchase_tasks(status, updated_at DESC);
CREATE INDEX idx_purchase_tasks_order_uuid      ON purchase_tasks(order_uuid);
CREATE INDEX idx_purchase_tasks_eta             ON purchase_tasks(eta) WHERE eta IS NOT NULL;
```

---

### purchase_task_items

Line items for a purchase task.

```sql
CREATE TABLE public.purchase_task_items (
    id                uuid      NOT NULL DEFAULT gen_random_uuid(),
    purchase_task_id  uuid      NOT NULL,
    order_item_id     uuid,
    sku               text,
    product_name      text      NOT NULL,
    quantity          integer   NOT NULL DEFAULT 1,
    notes             text,
    created_at        timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT purchase_task_items_pkey PRIMARY KEY (id)
);

CREATE INDEX idx_purchase_task_items_task ON purchase_task_items(purchase_task_id);
```

---

## 6. Warehouse

### warehouse_receipts

Records of goods received at the warehouse.

```sql
CREATE TABLE public.warehouse_receipts (
    id                  uuid      NOT NULL DEFAULT gen_random_uuid(),
    purchase_task_id    uuid      NOT NULL,
    receipt_number      text      NOT NULL,
    inspection_status   text      NOT NULL DEFAULT 'pending',
    received_items      jsonb     NOT NULL DEFAULT '[]',
    received_by_user_uuid uuid    NOT NULL,
    received_at        timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT warehouse_receipts_pkey              PRIMARY KEY (id),
    CONSTRAINT warehouse_receipts_receipt_number_unique UNIQUE (receipt_number),
    CONSTRAINT warehouse_receipt_inspection_check   CHECK (
        inspection_status IN ('pending', 'passed', 'failed', 'partial')
    )
);

CREATE INDEX idx_warehouse_receipt_task_time ON warehouse_receipts(purchase_task_id, received_at DESC);
```

---

### warehouse_records (Legacy)

Legacy fulfillment tracking (largely superseded by `warehouse_receipts` / `warehouse_shipments`).

```sql
CREATE TABLE public.warehouse_records (
    id                  bigserial   NOT NULL,
    procurement_task_id  bigint      NOT NULL,
    fulfillment_status  text,
    items               jsonb       NOT NULL DEFAULT '[]',
    history             jsonb       NOT NULL DEFAULT '[]',
    updated_by          bigint,
    created_at          timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT warehouse_records_pkey PRIMARY KEY (id),
    CONSTRAINT warehouse_procurement_unique UNIQUE (procurement_task_id)
);

CREATE SEQUENCE public.warehouse_records_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE warehouse_records_id_seq OWNED BY warehouse_records.id;
```

---

### warehouse_shipments

Records of goods shipped out from warehouse.

```sql
CREATE TABLE public.warehouse_shipments (
    id                  uuid       NOT NULL DEFAULT gen_random_uuid(),
    purchase_task_id    uuid       NOT NULL,
    shipment_number     text       NOT NULL,
    carrier            text,
    tracking_number     text,
    shipped_items       jsonb      NOT NULL DEFAULT '[]',
    shipped_by_user_uuid uuid      NOT NULL,
    shipped_at         timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT warehouse_shipments_pkey             PRIMARY KEY (id),
    CONSTRAINT warehouse_shipments_shipment_number_unique UNIQUE (shipment_number)
);

CREATE INDEX idx_warehouse_shipment_task_time ON warehouse_shipments(purchase_task_id, shipped_at DESC);
```

---

## 7. Invoices

### invoice_orders

Invoice records (separate from system orders).

```sql
CREATE TABLE public.invoice_orders (
    id                              bigserial    NOT NULL,
    legacy_id                       text,
    order_number                    text         NOT NULL,
    invoice_date                   date         NOT NULL,
    customer_full_name             text,
    customer_email                 text,
    phone_number                   text,
    country                        text,
    country_source                 text,
    address                        text,
    invoice_link                   text,
    invoice_status                 text         NOT NULL,
    expedited_shipping             boolean      NOT NULL DEFAULT false,
    fixed_discount                 numeric(14,2),
    percentage_discount            numeric(14,2),
    gift_box                       text         NOT NULL DEFAULT 'Has',
    amount_usd                     numeric(14,2) NOT NULL,
    recipient_paypal               text,
    created_by                     bigint,
    raw                            jsonb,
    created_at                     timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                     timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    order_date                     date,
    invoice_screenshot_attachment_id bigint,
    entity_uuid                    uuid          NOT NULL DEFAULT gen_random_uuid(),
    version                        integer       NOT NULL DEFAULT 1,

    CONSTRAINT invoice_orders_pkey                  PRIMARY KEY (id),
    CONSTRAINT invoice_orders_entity_uuid_unique   UNIQUE (entity_uuid),
    CONSTRAINT invoice_orders_legacy_id_unique     UNIQUE (legacy_id),
    CONSTRAINT invoice_orders_order_number_unique   UNIQUE (order_number)
);

CREATE SEQUENCE public.invoice_orders_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE invoice_orders_id_seq OWNED BY invoice_orders.id;

CREATE INDEX idx_invoice_order_number ON invoice_orders(order_number);
CREATE INDEX idx_invoice_date        ON invoice_orders(invoice_date DESC);
CREATE INDEX idx_invoice_entity_uuid ON invoice_orders(entity_uuid);
```

---

### invoice_items

Line items for an invoice.

```sql
CREATE TABLE public.invoice_items (
    id                  bigserial   NOT NULL,
    invoice_id          bigint      NOT NULL,
    product_name        text,
    description         text,
    quantity            integer     NOT NULL DEFAULT 1,
    price               numeric(14,2),
    notes               text,
    image_attachment_id bigint,
    entity_uuid         uuid        NOT NULL DEFAULT gen_random_uuid(),

    CONSTRAINT invoice_items_pkey               PRIMARY KEY (id),
    CONSTRAINT invoice_items_entity_uuid_unique UNIQUE (entity_uuid)
);

CREATE SEQUENCE public.invoice_items_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE invoice_items_id_seq OWNED BY invoice_items.id;
```

---

### invoice_adjustments

Invoice discounts, credits, shipping fees, etc.

```sql
CREATE TABLE public.invoice_adjustments (
    id                uuid         NOT NULL DEFAULT gen_random_uuid(),
    invoice_uuid      uuid         NOT NULL,
    adjustment_type   text         NOT NULL,
    amount            numeric(14,2),
    percentage        numeric(14,2),
    reason            text,
    created_by_user_uuid uuid,
    created_at        timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT invoice_adjustments_pkey PRIMARY KEY (id),
    CONSTRAINT invoice_adjustment_type_check CHECK (
        adjustment_type IN ('discount', 'credit', 'shipping', 'no_box', 'other')
    )
);

CREATE INDEX idx_invoice_adjustments_invoice_uuid ON invoice_adjustments(invoice_uuid);
```

---

### invoice_staff_allocations

Commission allocation for invoice orders.

```sql
CREATE TABLE public.invoice_staff_allocations (
    id                  bigserial     NOT NULL,
    invoice_id          bigint        NOT NULL,
    staff_code          text          NOT NULL,
    commission_percent  numeric(5,2),
    share_ratio         numeric(6,4)  NOT NULL DEFAULT 1,

    CONSTRAINT invoice_staff_allocations_pkey PRIMARY KEY (id)
);

CREATE SEQUENCE public.invoice_staff_allocations_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE invoice_staff_allocations_id_seq OWNED BY invoice_staff_allocations.id;
```

---

### invoice_operation_logs

Immutable audit log of invoice state changes.

```sql
CREATE TABLE public.invoice_operation_logs (
    id              uuid      NOT NULL DEFAULT gen_random_uuid(),
    invoice_uuid    uuid      NOT NULL,
    action          text      NOT NULL,
    before          jsonb,
    after           jsonb,
    actor_user_uuid uuid      NOT NULL,
    request_id      text,
    source          text      NOT NULL DEFAULT 'api',
    created_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT invoice_operation_logs_pkey PRIMARY KEY (id)
);

CREATE INDEX idx_invoice_operation_invoice_time ON invoice_operation_logs(invoice_uuid, created_at DESC);
```

---

## 8. PayPal / Finance

### paypal_accounts

Registered PayPal business accounts.

```sql
CREATE TABLE public.paypal_accounts (
    id          bigserial   NOT NULL,
    email       text        NOT NULL,
    account_name text,
    added_date  date,
    active      boolean     NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    meta        jsonb       NOT NULL DEFAULT '{}',

    CONSTRAINT paypal_accounts_pkey    PRIMARY KEY (id),
    CONSTRAINT paypal_accounts_email_unique UNIQUE (email)
);

CREATE SEQUENCE public.paypal_accounts_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE paypal_accounts_id_seq OWNED BY paypal_accounts.id;
```

---

### paypal_balance_entries

Historical balance snapshots per PayPal account.

```sql
CREATE TABLE public.paypal_balance_entries (
    id          bigserial    NOT NULL,
    account_id  bigint       NOT NULL,
    balance     numeric(14,2) NOT NULL,
    entered_by  bigint,
    created_at  timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT paypal_balance_entries_pkey PRIMARY KEY (id)
);

CREATE SEQUENCE public.paypal_balance_entries_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE paypal_balance_entries_id_seq OWNED BY paypal_balance_entries.id;
```

---

### paypal_reviews

PayPal account review/verification records.

```sql
CREATE TABLE public.paypal_reviews (
    id          bigserial  NOT NULL,
    account_id  bigint     NOT NULL,
    review_count integer   NOT NULL,
    entered_by  bigint,
    created_at  timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT paypal_reviews_pkey PRIMARY KEY (id)
);

CREATE SEQUENCE public.paypal_reviews_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE paypal_reviews_id_seq OWNED BY paypal_reviews.id;
```

---

### paypal_withdrawals

Withdrawal records from PayPal accounts.

```sql
CREATE TABLE public.paypal_withdrawals (
    id          bigserial    NOT NULL,
    account_id  bigint       NOT NULL,
    amount      numeric(14,2) NOT NULL,
    source      text,
    withdrawn_at date         NOT NULL,
    created_by  bigint,
    created_at  timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT paypal_withdrawals_pkey PRIMARY KEY (id)
);

CREATE SEQUENCE public.paypal_withdrawals_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE paypal_withdrawals_id_seq OWNED BY paypal_withdrawals.id;
```

---

## 9. Influencers

### influencers

Influencer profiles linked to orders.

```sql
CREATE TABLE public.influencers (
    id              uuid      NOT NULL DEFAULT gen_random_uuid(),
    display_name    text      NOT NULL,
    status          text      NOT NULL DEFAULT 'active',
    profile         jsonb     NOT NULL DEFAULT '{}',
    version         integer   NOT NULL DEFAULT 1,
    created_by_user_uuid uuid,
    created_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT influencers_pkey PRIMARY KEY (id)
);

CREATE INDEX idx_influencers_display_name ON influencers(display_name);
```

---

### influencer_domains

Verified domains associated with influencers.

```sql
CREATE TABLE public.influencer_domains (
    id              bigserial   NOT NULL,
    domain          text        NOT NULL,
    influencer_name text,
    confirmed       boolean     NOT NULL DEFAULT false,
    created_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT influencer_domains_pkey      PRIMARY KEY (id),
    CONSTRAINT influencer_domains_domain_unique UNIQUE (domain)
);

CREATE SEQUENCE public.influencer_domains_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE influencer_domains_id_seq OWNED BY influencer_domains.id;
```

---

### influencer_order_links

Links influencers to orders (many-to-many).

```sql
CREATE TABLE public.influencer_order_links (
    id                    uuid      NOT NULL DEFAULT gen_random_uuid(),
    influencer_id         uuid      NOT NULL,
    order_uuid            uuid      NOT NULL,
    source                text      NOT NULL DEFAULT 'manual',
    created_by_user_uuid  uuid,
    created_at            timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT influencer_order_links_pkey PRIMARY KEY (id),
    CONSTRAINT influencer_order_link_unique UNIQUE (influencer_id, order_uuid)
);
```

---

## 10. Workflow & Audit

### operation_cases

Operational cases/tickets opened by staff.

```sql
CREATE TABLE public.operation_cases (
    id                  uuid      NOT NULL DEFAULT gen_random_uuid(),
    case_type           text      NOT NULL,
    status              text      NOT NULL DEFAULT 'open',
    entity_type         text,
    entity_uuid         uuid,
    summary             text      NOT NULL,
    details             jsonb     NOT NULL DEFAULT '{}',
    version             integer   NOT NULL DEFAULT 1,
    owner_user_uuid     uuid,
    created_by_user_uuid uuid     NOT NULL,
    created_at          timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT operation_cases_pkey PRIMARY KEY (id)
);

CREATE INDEX idx_operation_cases_status_updated ON operation_cases(status, updated_at DESC);
```

---

### pending_completion_operations

Operations pending completion after order processing.

```sql
CREATE TABLE public.pending_completion_operations (
    operation_uuid          uuid      NOT NULL DEFAULT gen_random_uuid(),
    identity_type          text      NOT NULL,
    identity_key           text      NOT NULL,
    order_uuid             uuid      NOT NULL,
    business_date          date      NOT NULL,
    source_status          text      NOT NULL,
    target_status          text      NOT NULL,
    target_classification   text      NOT NULL,
    result                 jsonb     NOT NULL,
    completed_at            timestamptz NOT NULL,
    created_at              timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pending_completion_operations_pkey          PRIMARY KEY (operation_uuid),
    CONSTRAINT pending_completion_operation_identity_unique UNIQUE (identity_type, identity_key),
    CONSTRAINT pending_completion_operation_order_unique  UNIQUE (order_uuid),
    CONSTRAINT pending_completion_operation_classification_check CHECK (
        target_classification = 'payment_link'
    ),
    CONSTRAINT pending_completion_operation_identity_type_check CHECK (
        identity_type IN ('client', 'order', 'paypal')
    ),
    CONSTRAINT pending_completion_operation_source_check CHECK (
        source_status = 'pending'
    ),
    CONSTRAINT pending_completion_operation_target_check CHECK (
        target_status = 'completed'
    )
);
```

---

### audit_logs

Immutable audit trail. Rows are append-only.

```sql
CREATE TABLE public.audit_logs (
    id          bigserial   NOT NULL,
    user_id     bigint,
    action      text        NOT NULL,
    entity_type text        NOT NULL,
    entity_id   text,
    before      jsonb,
    after       jsonb,
    ip          text,
    created_at  timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT audit_logs_pkey PRIMARY KEY (id)
);

CREATE SEQUENCE public.audit_logs_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE audit_logs_id_seq OWNED BY audit_logs.id;

CREATE INDEX idx_audit_entity ON audit_logs(entity_type, entity_id);
CREATE INDEX idx_audit_time   ON audit_logs(created_at DESC);
```

---

### workflow_events

Generic event-sourcing log for workflow state machines.

```sql
CREATE TABLE public.workflow_events (
    id              uuid      NOT NULL DEFAULT gen_random_uuid(),
    entity_type     text      NOT NULL,
    entity_uuid     uuid      NOT NULL,
    event_type      text      NOT NULL,
    from_state      text,
    to_state        text,
    actor_user_uuid uuid      NOT NULL,
    request_id      text,
    source          text      NOT NULL DEFAULT 'api',
    before          jsonb,
    after           jsonb,
    created_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT workflow_events_pkey PRIMARY KEY (id)
);

CREATE INDEX idx_workflow_entity_time ON workflow_events(entity_type, entity_uuid, created_at DESC);
CREATE INDEX idx_workflow_actor_time  ON workflow_events(actor_user_uuid, created_at DESC);
```

---

## 11. System & Utilities

### attachments

File attachments with SHA-256 deduplication.

```sql
CREATE TABLE public.attachments (
    id              bigserial  NOT NULL,
    entity_type     text       NOT NULL,
    entity_id       bigint,
    file_path       text       NOT NULL,
    mime            text,
    size_bytes      bigint,
    sha256          text       NOT NULL,
    created_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    entity_uuid     uuid       NOT NULL DEFAULT gen_random_uuid(),

    CONSTRAINT attachments_pkey              PRIMARY KEY (id),
    CONSTRAINT attachments_entity_uuid_unique UNIQUE (entity_uuid)
);

CREATE SEQUENCE public.attachments_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE attachments_id_seq OWNED BY attachments.id;

CREATE INDEX idx_attachments_entity   ON attachments(entity_type, entity_id);
CREATE INDEX idx_attachments_sha256   ON attachments(sha256);
```

---

### background_jobs

Background job tracking (OCR, exports, report refresh).

```sql
CREATE TABLE public.background_jobs (
    id                  uuid      NOT NULL DEFAULT gen_random_uuid(),
    job_type            text      NOT NULL,
    status              text      NOT NULL DEFAULT 'queued',
    progress_percent    integer   NOT NULL DEFAULT 0,
    queue_job_id        text,
    input_attachment_uuid uuid,
    input_sha256        text,
    engine              text,
    model_version        text,
    input               jsonb     NOT NULL DEFAULT '{}',
    result              jsonb,
    error_code          text,
    error_message       text,
    created_by_user_uuid uuid      NOT NULL,
    cancel_requested    boolean   NOT NULL DEFAULT false,
    retry_count         integer   NOT NULL DEFAULT 0,
    created_at          timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at          timestamptz,
    finished_at         timestamptz,
    expires_at          timestamptz NOT NULL DEFAULT (now() + '24:00:00'),
    input_fingerprint   text,

    CONSTRAINT background_jobs_pkey              PRIMARY KEY (id),
    CONSTRAINT background_job_progress_check     CHECK (progress_percent >= 0 AND progress_percent <= 100),
    CONSTRAINT background_job_status_check        CHECK (
        status IN ('queued', 'processing', 'succeeded', 'failed', 'cancelled')
    ),
    CONSTRAINT background_job_type_check          CHECK (
        job_type IN ('invoice_ocr', 'batch_export', 'report_refresh')
    )
);

CREATE INDEX idx_background_jobs_status_created ON background_jobs(status, created_at DESC);
CREATE INDEX idx_background_jobs_expiry         ON background_jobs(expires_at) WHERE status != 'succeeded';
CREATE INDEX idx_background_jobs_actor_hash    ON background_jobs(created_by_user_uuid, input_sha256);
```

---

### idempotency_keys

Prevents duplicate API requests.

```sql
CREATE TABLE public.idempotency_keys (
    id              uuid      NOT NULL DEFAULT gen_random_uuid(),
    actor_user_uuid uuid      NOT NULL,
    scope           text      NOT NULL,
    idempotency_key text      NOT NULL,
    request_hash    text      NOT NULL,
    state           text      NOT NULL DEFAULT 'processing',
    response_status integer,
    response_body   jsonb,
    created_at      timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      timestamptz NOT NULL DEFAULT (now() + '24:00:00'),

    CONSTRAINT idempotency_keys_pkey               PRIMARY KEY (id),
    CONSTRAINT idempotency_actor_scope_key_unique   UNIQUE (actor_user_uuid, scope, idempotency_key),
    CONSTRAINT idempotency_state_check              CHECK (state IN ('processing', 'completed'))
);

CREATE INDEX idx_idempotency_expiry ON idempotency_keys(expires_at);
```

---

### daily_stats

Denormalized daily aggregated statistics by channel and staff.

```sql
CREATE TABLE public.daily_stats (
    id              bigserial     NOT NULL,
    stat_date       date          NOT NULL,
    channel         text          NOT NULL,
    staff_code      text          NOT NULL DEFAULT '',
    orders_count    numeric(18,10) NOT NULL DEFAULT 0,
    items_count     numeric(18,10) NOT NULL DEFAULT 0,
    usd_amount      numeric(18,10) NOT NULL DEFAULT 0,
    updated_at      timestamptz    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT daily_stats_pkey                        PRIMARY KEY (id),
    CONSTRAINT daily_stats_date_channel_staff_unique   UNIQUE (stat_date, channel, staff_code)
);

CREATE SEQUENCE public.daily_stats_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE daily_stats_id_seq OWNED BY daily_stats.id;
```

---

### exchange_rates

Currency exchange rates to USD.

```sql
CREATE TABLE public.exchange_rates (
    id              bigserial    NOT NULL,
    currency        text         NOT NULL,
    rate_to_usd     numeric(18,8) NOT NULL,
    effective_date  date         NOT NULL,

    CONSTRAINT exchange_rates_pkey                   PRIMARY KEY (id),
    CONSTRAINT exchange_rates_currency_date_unique   UNIQUE (currency, effective_date)
);

CREATE SEQUENCE public.exchange_rates_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE exchange_rates_id_seq OWNED BY exchange_rates.id;
```

---

### shipment_tracking_events

External carrier tracking events.

```sql
CREATE TABLE public.shipment_tracking_events (
    id                  bigserial   NOT NULL,
    procurement_task_id bigint,
    provider            text        NOT NULL,
    tracking_number     text        NOT NULL,
    status              text,
    substatus           text,
    event_id            text,
    raw                 jsonb       NOT NULL DEFAULT '{}',
    occurred_at         timestamptz,
    created_at          timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT shipment_tracking_events_pkey              PRIMARY KEY (id),
    CONSTRAINT tracking_provider_event_unique             UNIQUE (provider, tracking_number, event_id)
);

CREATE SEQUENCE public.shipment_tracking_events_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE shipment_tracking_events_id_seq OWNED BY shipment_tracking_events.id;

CREATE INDEX idx_tracking_number_time ON shipment_tracking_events(tracking_number, occurred_at DESC);
```

---

### site_classification_reclassifications

Tracks when an order's classification changes.

```sql
CREATE TABLE public.site_classification_reclassifications (
    release_id                 text      NOT NULL,
    order_id                   text      NOT NULL,
    source_domain              text      NOT NULL,
    previous_classification    text      NOT NULL,
    previous_influencer_name  text,
    target_classification      text      NOT NULL,
    target_influencer_name     text,
    created_at                timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT site_classification_reclassifications_pkey PRIMARY KEY (release_id)
);
```

---

### system_state

Key-value store for distributed system state.

```sql
CREATE TABLE public.system_state (
    key         text    NOT NULL,
    value       jsonb   NOT NULL DEFAULT '{}',
    updated_at  timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT system_state_pkey PRIMARY KEY (key)
);
```

---

### legacy_dashboard_days

Cached daily dashboard payload snapshots.

```sql
CREATE TABLE public.legacy_dashboard_days (
    day                        date      NOT NULL,
    payload                    jsonb     NOT NULL,
    source_sha256              varchar(64) NOT NULL,
    source_size_bytes          bigint    NOT NULL,
    snapshot_cutoff_asia_shanghai text    NOT NULL,
    created_at                 timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT legacy_dashboard_days_pkey PRIMARY KEY (day)
);

CREATE INDEX idx_legacy_dashboard_days_updated ON legacy_dashboard_days(updated_at DESC);
```

---

### legacy_import_items

Tracks records imported from external sources.

```sql
CREATE TABLE public.legacy_import_items (
    id            bigserial   NOT NULL,
    source_key    text        NOT NULL,
    source_sha256 text        NOT NULL,
    entity_type   text        NOT NULL,
    entity_id     text,
    imported_at   timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT legacy_import_items_pkey          PRIMARY KEY (id),
    CONSTRAINT legacy_import_items_source_key_unique UNIQUE (source_key)
);

CREATE SEQUENCE public.legacy_import_items_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;
ALTER SEQUENCE legacy_import_items_id_seq OWNED BY legacy_import_items.id;
```

---

## Appendix A: ER Diagram Summary

```
users ───────────────────────────────────────────────────────────┐
  ├─ api_tokens (1:N)                                             │
  ├─ user_roles (legacy, 1:N) ──→ roles (legacy, UUID)           │
  │   └─ role_permissions (legacy, N:M) ──→ permissions (legacy)  │
  ├─ order_user_overrides (N:1 via staff_code)                     │
  ├─ order_staff_allocations (N:1 via staff_code)                  │
  └─ workflow_events (N:1 via actor_user_uuid)                     │

orders ──(1:N)─→ order_items
  ├─(1:N)─→ order_user_overrides
  ├─(1:N)─→ order_staff_allocations
  ├─(1:N)─→ order_status_observations
  └─(1:N)─→ influencer_order_links ──→ influencers

invoice_orders ──(1:N)─→ invoice_items
  ├─(1:N)─→ invoice_adjustments
  ├─(1:N)─→ invoice_staff_allocations
  └─(1:N)─→ invoice_operation_logs

purchase_tasks ──(1:N)─→ purchase_task_items
  ├─(1:N)─→ warehouse_receipts
  ├─(1:N)─→ warehouse_shipments
  └─(1:N)─→ shipment_tracking_events

attachments (polymorphic: entity_type + entity_id/entity_uuid)
background_jobs (standalone, FK to users.entity_uuid)
idempotency_keys (FK to users.entity_uuid)
audit_logs (FK to users.id)
```

## Appendix B: Key Constraints & Checks

| Table | Constraint | Allowed Values |
|---|---|---|
| `users` | `users_role_check` | `admin`, `finance`, `cs`, `customer_service`, `customer_service_client`, `purchasing`, `warehouse`, `influencer`, `operations`, `viewer` |
| `background_jobs` | `background_job_status_check` | `queued`, `processing`, `succeeded`, `failed`, `cancelled` |
| `background_jobs` | `background_job_type_check` | `invoice_ocr`, `batch_export`, `report_refresh` |
| `purchase_tasks` | `purchase_tasks_status_check` | `pending_purchase`, `waiting_supplier_shipment`, `arrived_warehouse`, `inspection`, `shipped`, `exchange_pending`, `exchange_in_progress`, `return_pending`, `return_in_progress`, `cancelled` |
| `warehouse_receipts` | `warehouse_receipt_inspection_check` | `pending`, `passed`, `failed`, `partial` |
| `invoice_adjustments` | `invoice_adjustment_type_check` | `discount`, `credit`, `shipping`, `no_box`, `other` |

## Appendix C: UUID vs BIGSERIAL Strategy

| Pattern | Tables |
|---|---|
| `bigserial` (auto-increment) | `users`, `orders`, `purchase_tasks`, `invoice_orders`, `paypal_*`, `api_tokens` |
| `uuid DEFAULT gen_random_uuid()` | `order_items`, `operation_cases`, `invoice_adjustments`, `invoice_operation_logs`, `workflow_events`, `background_jobs`, `idempotency_keys`, `warehouse_receipts`, `warehouse_shipments`, `purchase_task_items`, `pending_completion_operations` |
| `text` (business key) | `orders.order_id`, `invoice_orders.order_number`, `users.username` |
