-- Generated from newsql.md by scripts/generate-local-schema.py.

-- Empty database only. FK creation is deferred until all tables exist.

-- Missing bigint sequences / users UUID default and partial UNIQUE syntax are repaired.

CREATE SEQUENCE saveb_visible_order_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE daily_stats_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

CREATE SEQUENCE exchange_rates_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE order_status_observations_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE order_staff_performance_projection_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE invoice_orders_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE invoice_items_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE invoice_staff_allocations_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE paypal_accounts_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE paypal_balance_entries_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE paypal_reviews_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE paypal_withdrawals_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE influencer_domains_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE attachments_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE procurement_tasks_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE procurement_removed_orders_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE saveb_purchase_task_number_seq START WITH 100000 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE warehouse_records_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE shipment_tracking_events_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE legacy_import_items_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE;

CREATE SEQUENCE users_id_seq START WITH 1 INCREMENT BY 1;

CREATE SEQUENCE api_tokens_id_seq START WITH 1 INCREMENT BY 1;

CREATE SEQUENCE roles_id_seq START WITH 1 INCREMENT BY 1;

CREATE SEQUENCE permissions_id_seq START WITH 1 INCREMENT BY 1;

CREATE SEQUENCE role_permissions_id_seq START WITH 1 INCREMENT BY 1;

CREATE SEQUENCE user_roles_id_seq START WITH 1 INCREMENT BY 1;

CREATE SEQUENCE audit_logs_id_seq START WITH 1 INCREMENT BY 1;

CREATE SEQUENCE orders_id_seq START WITH 1 INCREMENT BY 1;

CREATE TABLE users (
    id                     bigint          NOT NULL DEFAULT nextval('users_id_seq'),
    username               varchar(64)     NOT NULL,
    password_hash          varchar(255)    NOT NULL,
    display_name           varchar(100)    NOT NULL,
    staff_code             varchar(64)     NULL,
    active                 boolean         NOT NULL DEFAULT true,
    must_change_password   boolean         NOT NULL DEFAULT false,
    role_id                bigint          NULL,
    entity_uuid            uuid            NOT NULL DEFAULT gen_random_uuid(),
    created_at             timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at             timestamptz(6)  NULL,
    CONSTRAINT users_pkey                 PRIMARY KEY (id),
    CONSTRAINT users_username_unique      UNIQUE (username),
    CONSTRAINT users_entity_uuid_unique   UNIQUE (entity_uuid)
);

CREATE TABLE api_tokens (
    id              bigint          NOT NULL DEFAULT nextval('api_tokens_id_seq'),
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
    CONSTRAINT api_tokens_token_hash_unique UNIQUE (token_hash)
);

CREATE TABLE roles (
    id              bigint          NOT NULL DEFAULT nextval('roles_id_seq'),
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

CREATE TABLE permissions (
    id                bigint          NOT NULL DEFAULT nextval('permissions_id_seq'),
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

CREATE TABLE role_permissions (
    id              bigint          NOT NULL DEFAULT nextval('role_permissions_id_seq'),
    role_id         bigint          NOT NULL,
    permission_id   bigint          NOT NULL,
    created_at      timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT role_permissions_pkey PRIMARY KEY (id),
    CONSTRAINT role_permissions_unique            UNIQUE (role_id, permission_id)
);

CREATE TABLE user_roles (
    id                    bigint          NOT NULL DEFAULT nextval('user_roles_id_seq'),
    user_id               bigint          NOT NULL,
    role_id               bigint          NOT NULL,
    granted_by_user_id    bigint          NULL,
    created_at            timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            timestamptz(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT user_roles_pkey                 PRIMARY KEY (id),
    CONSTRAINT user_roles_user_role_unique      UNIQUE (user_id, role_id)
);

CREATE TABLE audit_logs (
    id           bigint          NOT NULL DEFAULT nextval('audit_logs_id_seq'),
    user_id      bigint          NULL,
    action       varchar(64)     NOT NULL,
    entity_type  varchar(64)     NOT NULL,
    entity_id    varchar(64)     NULL,
    ip           varchar(45)     NULL,
    details      text            NULL,
    created_at   timestamptz     NULL,
    CONSTRAINT audit_logs_pkey PRIMARY KEY (id)
);

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
    CONSTRAINT order_items_pkey          PRIMARY KEY (id)
);

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
    CONSTRAINT order_user_override_identity_unique       UNIQUE (order_key, order_key_type),
    CONSTRAINT order_user_override_key_type_chk          CHECK (order_key_type IN ('client', 'order', 'paypal')),
    CONSTRAINT order_user_override_completed_chk         CHECK (status_override = 'completed'),
    CONSTRAINT order_user_override_version_chk           CHECK (version > 0)
);

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
    CONSTRAINT order_staff_allocation_unique         UNIQUE (order_override_id, staff_code),
    CONSTRAINT order_staff_allocation_role_chk       CHECK (participant_role IN ('primary', 'collaborator')),
    CONSTRAINT order_staff_allocation_share_chk      CHECK (share_ratio > 0 AND share_ratio <= 1)
);

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
    CONSTRAINT pending_completion_identity_unique   UNIQUE (identity_type, identity_key),
    CONSTRAINT pending_completion_order_unique      UNIQUE (order_uuid),
    CONSTRAINT pending_completion_identity_type_chk CHECK (identity_type IN ('client', 'order', 'paypal')),
    CONSTRAINT pending_completion_source_chk        CHECK (source_status = 'pending'),
    CONSTRAINT pending_completion_target_chk        CHECK (target_status = 'completed'),
    CONSTRAINT pending_completion_classification_chk CHECK (target_classification = 'payment_link')
);

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
    CONSTRAINT order_status_observation_operation_unique   UNIQUE (operation_uuid)
);

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
    CONSTRAINT order_staff_performance_projection_unique       UNIQUE (operation_uuid, staff_code),
    CONSTRAINT order_staff_performance_projection_share_chk    CHECK (share_ratio > 0 AND share_ratio <= 1),
    CONSTRAINT order_staff_performance_projection_commission_chk CHECK (commission_percent IS NULL OR commission_percent >= 0)
);

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
    CONSTRAINT invoice_items_entity_uuid_unique UNIQUE (entity_uuid)
);

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
    CONSTRAINT invoice_adjustment_type_chk         CHECK (adjustment_type IN ('discount', 'credit', 'shipping', 'no_box', 'other'))
);

CREATE TABLE invoice_staff_allocations (
    id                 bigint          NOT NULL DEFAULT nextval('invoice_staff_allocations_id_seq'),
    invoice_id         bigint          NOT NULL,
    staff_code         text            NOT NULL,
    commission_percent numeric(5,2)    NOT NULL DEFAULT 0,
    share_ratio        numeric(6,4)    NOT NULL DEFAULT 1,
    created_at         timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at         timestamptz     NULL,
    CONSTRAINT invoice_staff_allocations_pkey     PRIMARY KEY (id)
);

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
    CONSTRAINT invoice_operation_logs_pkey             PRIMARY KEY (id)
);

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

CREATE TABLE paypal_balance_entries (
    id           bigint          NOT NULL DEFAULT nextval('paypal_balance_entries_id_seq'),
    account_id   bigint          NOT NULL,
    balance      numeric(14,2)   NOT NULL,
    entered_by   bigint          NULL,
    created_at   timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at   timestamptz     NULL,
    CONSTRAINT paypal_balance_entries_pkey         PRIMARY KEY (id)
);

CREATE TABLE paypal_reviews (
    id            bigint          NOT NULL DEFAULT nextval('paypal_reviews_id_seq'),
    account_id    bigint          NOT NULL,
    review_count  integer         NOT NULL,
    entered_by    bigint          NULL,
    created_at    timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    timestamptz     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at    timestamptz     NULL,
    CONSTRAINT paypal_reviews_pkey        PRIMARY KEY (id)
);

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
    CONSTRAINT paypal_withdrawals_pkey        PRIMARY KEY (id)
);

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
    CONSTRAINT influencers_pkey             PRIMARY KEY (id)
);

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
    CONSTRAINT influencer_order_link_unique         UNIQUE (influencer_id, order_uuid)
);

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
    CONSTRAINT workflow_events_pkey           PRIMARY KEY (id)
);

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
    CONSTRAINT operation_cases_pkey                 PRIMARY KEY (id)
);

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
    CONSTRAINT idempotency_actor_scope_key_unique UNIQUE (actor_user_uuid, scope, idempotency_key),
    CONSTRAINT idempotency_state_check            CHECK (state IN ('processing', 'completed'))
);

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
    CONSTRAINT background_job_progress_check     CHECK (progress_percent >= 0 AND progress_percent <= 100),
    CONSTRAINT background_job_status_check       CHECK (status IN ('queued','processing','succeeded','failed','cancelled')),
    CONSTRAINT background_job_type_check         CHECK (job_type  IN ('invoice_ocr','batch_export','report_refresh'))
);

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
    CONSTRAINT procurement_purchase_status_check   CHECK (
        purchase_status IN (
            'pending_purchase','supplier_shipping_pending','warehouse_arrived',
            'exchange_in_progress','return_in_progress',
            'customer_confirm_pending','shipped'
        )
    )
);

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
    CONSTRAINT purchase_tasks_source_check        CHECK (source IN ('system','manual')),
    CONSTRAINT purchase_tasks_status_check        CHECK (status IN (
        'pending_purchase','waiting_supplier_shipment','arrived_warehouse',
        'inspection','shipped','exchange_pending','exchange_in_progress',
        'return_pending','return_in_progress','cancelled'
    )),
    CONSTRAINT purchase_tasks_type_check          CHECK (task_type IN ('order_purchase','replacement','exchange','other')),
    CONSTRAINT purchase_tasks_system_order_check  CHECK (source = 'manual' OR order_uuid IS NOT NULL)
);

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
    CONSTRAINT purchase_task_items_pkey             PRIMARY KEY (id)
);

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
    CONSTRAINT warehouse_procurement_unique      UNIQUE (procurement_task_id)
);

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
    CONSTRAINT warehouse_receipt_inspection_check      CHECK (inspection_status IN ('pending','passed','failed','partial'))
);

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
    CONSTRAINT warehouse_shipments_shipment_number_unique UNIQUE (shipment_number)
);

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
    CONSTRAINT tracking_provider_event_unique           UNIQUE (provider, tracking_number, event_id)
);

CREATE TABLE system_state (
    key         text          NOT NULL,
    value       jsonb         NOT NULL DEFAULT '{}'::jsonb,
    updated_at  timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at  timestamptz   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at  timestamptz   NULL,
    CONSTRAINT system_state_pkey PRIMARY KEY (key)
);

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

ALTER SEQUENCE users_id_seq OWNED BY users.id;

ALTER SEQUENCE api_tokens_id_seq OWNED BY api_tokens.id;

ALTER SEQUENCE roles_id_seq OWNED BY roles.id;

ALTER SEQUENCE permissions_id_seq OWNED BY permissions.id;

ALTER SEQUENCE role_permissions_id_seq OWNED BY role_permissions.id;

ALTER SEQUENCE user_roles_id_seq OWNED BY user_roles.id;

ALTER SEQUENCE audit_logs_id_seq OWNED BY audit_logs.id;

ALTER SEQUENCE orders_id_seq OWNED BY orders.id;

ALTER SEQUENCE saveb_visible_order_id_seq OWNED BY orders.visible_order_id;

ALTER SEQUENCE daily_stats_id_seq OWNED BY daily_stats.id;

ALTER SEQUENCE exchange_rates_id_seq OWNED BY exchange_rates.id;

ALTER SEQUENCE order_status_observations_id_seq OWNED BY order_status_observations.id;

ALTER SEQUENCE order_staff_performance_projection_id_seq OWNED BY order_staff_performance_projection.id;

ALTER SEQUENCE invoice_orders_id_seq OWNED BY invoice_orders.id;

ALTER SEQUENCE invoice_items_id_seq OWNED BY invoice_items.id;

ALTER SEQUENCE invoice_staff_allocations_id_seq OWNED BY invoice_staff_allocations.id;

ALTER SEQUENCE paypal_accounts_id_seq OWNED BY paypal_accounts.id;

ALTER SEQUENCE paypal_balance_entries_id_seq OWNED BY paypal_balance_entries.id;

ALTER SEQUENCE paypal_reviews_id_seq OWNED BY paypal_reviews.id;

ALTER SEQUENCE paypal_withdrawals_id_seq OWNED BY paypal_withdrawals.id;

ALTER SEQUENCE influencer_domains_id_seq OWNED BY influencer_domains.id;

ALTER SEQUENCE attachments_id_seq OWNED BY attachments.id;

ALTER SEQUENCE procurement_tasks_id_seq OWNED BY procurement_tasks.id;

ALTER SEQUENCE procurement_removed_orders_id_seq OWNED BY procurement_removed_orders.id;

ALTER SEQUENCE saveb_purchase_task_number_seq OWNED BY purchase_tasks.task_number;

ALTER SEQUENCE warehouse_records_id_seq OWNED BY warehouse_records.id;

ALTER SEQUENCE shipment_tracking_events_id_seq OWNED BY shipment_tracking_events.id;

ALTER SEQUENCE legacy_import_items_id_seq OWNED BY legacy_import_items.id;

CREATE INDEX users_role_id_index ON users(role_id);

CREATE INDEX users_staff_code_index ON users(staff_code);

CREATE INDEX api_tokens_user_id_index ON api_tokens(user_id);

CREATE INDEX api_tokens_expires_at_index ON api_tokens(expires_at) WHERE expires_at IS NOT NULL;

CREATE INDEX roles_status_sort_index ON roles(status, sort);

CREATE INDEX permissions_parent_id_sort_index ON permissions(parent_id, sort);

CREATE INDEX permissions_type_status_index   ON permissions(type, status);

CREATE INDEX permissions_level_status_index  ON permissions(level, status);

CREATE INDEX role_permissions_permission_id_index ON role_permissions(permission_id);

CREATE INDEX user_roles_role_id_index ON user_roles(role_id);

CREATE INDEX audit_logs_user_id_index        ON audit_logs(user_id);

CREATE INDEX audit_logs_entity_index         ON audit_logs(entity_type, entity_id);

CREATE INDEX audit_logs_created_at_index     ON audit_logs(created_at);

CREATE INDEX idx_orders_time           ON orders(order_time);

CREATE INDEX idx_orders_status         ON orders(order_status);

CREATE INDEX idx_orders_staff          ON orders(staff_code);

CREATE INDEX idx_orders_paypal         ON orders(paypal_order_id);

CREATE INDEX idx_orders_class          ON orders(classification);

CREATE INDEX idx_orders_client_order_id ON orders(client_order_id);

CREATE INDEX idx_orders_entity_uuid    ON orders(entity_uuid);

CREATE INDEX idx_order_items_order_uuid ON order_items(order_uuid);

CREATE INDEX idx_order_user_overrides_order_uuid ON order_user_overrides(order_uuid);

CREATE INDEX idx_order_user_overrides_updated_at ON order_user_overrides(updated_at DESC);

CREATE INDEX idx_order_staff_allocations_staff ON order_staff_allocations(staff_code);

CREATE INDEX idx_order_status_observation_stable_timeline ON order_status_observations(order_source_stable_key, observed_at DESC);

CREATE INDEX idx_order_status_observation_business_status ON order_status_observations(normalized_status, observed_at DESC);

CREATE INDEX idx_order_staff_performance_month_staff ON order_staff_performance_projection(business_date, staff_code);

CREATE INDEX idx_invoice_order_number ON invoice_orders(order_number);

CREATE INDEX idx_invoice_date        ON invoice_orders(invoice_date DESC);

CREATE INDEX idx_invoice_entity_uuid ON invoice_orders(entity_uuid);

CREATE INDEX idx_invoice_items_invoice_id ON invoice_items(invoice_id);

CREATE INDEX idx_invoice_adjustments_invoice_uuid ON invoice_adjustments(invoice_uuid);

CREATE INDEX idx_invoice_staff_alloc_invoice_id ON invoice_staff_allocations(invoice_id);

CREATE INDEX idx_invoice_operation_invoice_time ON invoice_operation_logs(invoice_uuid, created_at DESC);

CREATE INDEX idx_paypal_balance_account_id ON paypal_balance_entries(account_id);

CREATE INDEX idx_paypal_reviews_account_id ON paypal_reviews(account_id);

CREATE INDEX idx_paypal_withdrawals_account_id ON paypal_withdrawals(account_id);

CREATE INDEX idx_influencers_display_name ON influencers(display_name);

CREATE INDEX idx_influencer_domains_domain       ON influencer_domains(domain);

CREATE INDEX idx_influencer_domains_influencer  ON influencer_domains(influencer_name) WHERE influencer_name IS NOT NULL;

CREATE INDEX idx_site_classification_order_id ON site_classification_reclassifications(order_id);

CREATE INDEX idx_attachments_entity  ON attachments(entity_type, entity_id);

CREATE INDEX idx_attachments_sha256  ON attachments(sha256);

CREATE INDEX idx_workflow_entity_time ON workflow_events(entity_type, entity_uuid, created_at DESC);

CREATE INDEX idx_workflow_actor_time  ON workflow_events(actor_user_uuid, created_at DESC);

CREATE INDEX idx_operation_cases_status_updated ON operation_cases(status, updated_at DESC);

CREATE INDEX idx_idempotency_expiry ON idempotency_keys(expires_at);

CREATE INDEX idx_background_jobs_status_created ON background_jobs(status, created_at DESC);

CREATE INDEX idx_background_jobs_expiry         ON background_jobs(expires_at) WHERE status <> 'succeeded';

CREATE INDEX idx_background_jobs_actor_hash     ON background_jobs(created_by_user_uuid, input_sha256);

CREATE INDEX idx_proc_status       ON procurement_tasks(purchase_status);

CREATE INDEX idx_proc_entity_uuid  ON procurement_tasks(entity_uuid);

CREATE INDEX idx_purchase_tasks_type_created    ON purchase_tasks(task_type, created_at DESC);

CREATE INDEX idx_purchase_tasks_status_updated  ON purchase_tasks(status, updated_at DESC);

CREATE INDEX idx_purchase_tasks_order_uuid      ON purchase_tasks(order_uuid);

CREATE INDEX idx_purchase_tasks_eta             ON purchase_tasks(eta) WHERE eta IS NOT NULL;

CREATE INDEX idx_purchase_task_items_task ON purchase_task_items(purchase_task_id);

CREATE INDEX idx_warehouse_receipt_task_time ON warehouse_receipts(purchase_task_id, received_at DESC);

CREATE INDEX idx_warehouse_shipment_task_time ON warehouse_shipments(purchase_task_id, shipped_at DESC);

CREATE INDEX idx_tracking_number_time ON shipment_tracking_events(tracking_number, occurred_at DESC);

CREATE INDEX idx_legacy_dashboard_days_updated ON legacy_dashboard_days(updated_at DESC);

CREATE UNIQUE INDEX purchase_tasks_order_uuid_unique ON purchase_tasks (order_uuid) WHERE order_uuid IS NOT NULL;

ALTER TABLE users ADD CONSTRAINT users_role_id_fkey         FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL;

ALTER TABLE api_tokens ADD CONSTRAINT api_tokens_user_id_fkey   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE role_permissions ADD CONSTRAINT role_permissions_role_fkey        FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE;

ALTER TABLE role_permissions ADD CONSTRAINT role_permissions_permission_fkey  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE;

ALTER TABLE user_roles ADD CONSTRAINT user_roles_user_fkey            FOREIGN KEY (user_id)            REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE user_roles ADD CONSTRAINT user_roles_role_fkey            FOREIGN KEY (role_id)            REFERENCES roles(id) ON DELETE CASCADE;

ALTER TABLE user_roles ADD CONSTRAINT user_roles_granted_by_fkey      FOREIGN KEY (granted_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE order_items ADD CONSTRAINT order_items_order_uuid_fk FOREIGN KEY (order_uuid) REFERENCES orders(entity_uuid) ON DELETE CASCADE;

ALTER TABLE order_user_overrides ADD CONSTRAINT order_user_overrides_order_uuid_fk        FOREIGN KEY (order_uuid)           REFERENCES orders(entity_uuid) ON DELETE SET NULL;

ALTER TABLE order_user_overrides ADD CONSTRAINT order_user_overrides_updated_by_user_fk   FOREIGN KEY (updated_by_user_uuid) REFERENCES users(entity_uuid);

ALTER TABLE order_staff_allocations ADD CONSTRAINT order_staff_allocation_override_fk    FOREIGN KEY (order_override_id) REFERENCES order_user_overrides(id) ON DELETE CASCADE;

ALTER TABLE pending_completion_operations ADD CONSTRAINT pending_completion_order_uuid_fk     FOREIGN KEY (order_uuid) REFERENCES orders(entity_uuid) ON DELETE CASCADE;

ALTER TABLE order_status_observations ADD CONSTRAINT order_status_observations_order_uuid_fk     FOREIGN KEY (order_uuid)     REFERENCES orders(entity_uuid)                         ON DELETE CASCADE;

ALTER TABLE order_status_observations ADD CONSTRAINT order_status_observations_operation_uuid_fk FOREIGN KEY (operation_uuid) REFERENCES pending_completion_operations(operation_uuid) ON DELETE SET NULL;

ALTER TABLE order_staff_performance_projection ADD CONSTRAINT order_staff_performance_projection_operation_fk FOREIGN KEY (operation_uuid) REFERENCES pending_completion_operations(operation_uuid) ON DELETE CASCADE;

ALTER TABLE order_staff_performance_projection ADD CONSTRAINT order_staff_performance_projection_order_fk     FOREIGN KEY (order_uuid)     REFERENCES orders(entity_uuid)                        ON DELETE CASCADE;

ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_invoice_id_fk      FOREIGN KEY (invoice_id) REFERENCES invoice_orders(id) ON DELETE CASCADE;

ALTER TABLE invoice_adjustments ADD CONSTRAINT invoice_adjustments_invoice_uuid_fk FOREIGN KEY (invoice_uuid)         REFERENCES invoice_orders(entity_uuid) ON DELETE CASCADE;

ALTER TABLE invoice_adjustments ADD CONSTRAINT invoice_adjustments_user_uuid_fk    FOREIGN KEY (created_by_user_uuid) REFERENCES users(entity_uuid)        ON DELETE SET NULL;

ALTER TABLE invoice_staff_allocations ADD CONSTRAINT invoice_staff_allocations_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoice_orders(id) ON DELETE CASCADE;

ALTER TABLE invoice_operation_logs ADD CONSTRAINT invoice_operation_logs_invoice_uuid_fk  FOREIGN KEY (invoice_uuid)    REFERENCES invoice_orders(entity_uuid) ON DELETE CASCADE;

ALTER TABLE invoice_operation_logs ADD CONSTRAINT invoice_operation_logs_user_fk          FOREIGN KEY (actor_user_uuid) REFERENCES users(entity_uuid)        ON DELETE CASCADE;

ALTER TABLE paypal_balance_entries ADD CONSTRAINT paypal_balance_entries_account_fk   FOREIGN KEY (account_id) REFERENCES paypal_accounts(id) ON DELETE CASCADE;

ALTER TABLE paypal_reviews ADD CONSTRAINT paypal_reviews_account_fk  FOREIGN KEY (account_id) REFERENCES paypal_accounts(id) ON DELETE CASCADE;

ALTER TABLE paypal_withdrawals ADD CONSTRAINT paypal_withdrawals_account_fk  FOREIGN KEY (account_id) REFERENCES paypal_accounts(id) ON DELETE CASCADE;

ALTER TABLE influencers ADD CONSTRAINT influencers_creator_fk       FOREIGN KEY (created_by_user_uuid) REFERENCES users(entity_uuid) ON DELETE SET NULL;

ALTER TABLE influencer_order_links ADD CONSTRAINT influencer_order_links_influencer_fk FOREIGN KEY (influencer_id)        REFERENCES influencers(id)     ON DELETE CASCADE;

ALTER TABLE influencer_order_links ADD CONSTRAINT influencer_order_links_order_fk      FOREIGN KEY (order_uuid)           REFERENCES orders(entity_uuid) ON DELETE CASCADE;

ALTER TABLE influencer_order_links ADD CONSTRAINT influencer_order_links_user_fk       FOREIGN KEY (created_by_user_uuid) REFERENCES users(entity_uuid)  ON DELETE SET NULL;

ALTER TABLE workflow_events ADD CONSTRAINT workflow_events_actor_fk       FOREIGN KEY (actor_user_uuid) REFERENCES users(entity_uuid) ON DELETE CASCADE;

ALTER TABLE operation_cases ADD CONSTRAINT operation_cases_owner_fk             FOREIGN KEY (owner_user_uuid)      REFERENCES users(entity_uuid) ON DELETE SET NULL;

ALTER TABLE operation_cases ADD CONSTRAINT operation_cases_creator_fk           FOREIGN KEY (created_by_user_uuid) REFERENCES users(entity_uuid) ON DELETE CASCADE;

ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_actor_fk         FOREIGN KEY (actor_user_uuid) REFERENCES users(entity_uuid) ON DELETE CASCADE;

ALTER TABLE background_jobs ADD CONSTRAINT background_jobs_creator_fk        FOREIGN KEY (created_by_user_uuid)  REFERENCES users(entity_uuid)                ON DELETE CASCADE;

ALTER TABLE background_jobs ADD CONSTRAINT background_jobs_attachment_fk     FOREIGN KEY (input_attachment_uuid) REFERENCES attachments(entity_uuid)       ON DELETE SET NULL;

ALTER TABLE procurement_tasks ADD CONSTRAINT procurement_tasks_order_pk_fk       FOREIGN KEY (order_pk) REFERENCES orders(id);

ALTER TABLE purchase_tasks ADD CONSTRAINT purchase_tasks_order_uuid_fk       FOREIGN KEY (order_uuid)             REFERENCES orders(entity_uuid)         ON DELETE SET NULL;

ALTER TABLE purchase_tasks ADD CONSTRAINT purchase_tasks_legacy_proc_fk      FOREIGN KEY (legacy_procurement_id)  REFERENCES procurement_tasks(id)      ON DELETE SET NULL;

ALTER TABLE purchase_tasks ADD CONSTRAINT purchase_tasks_created_by_fk       FOREIGN KEY (created_by_user_uuid)   REFERENCES users(entity_uuid)        ON DELETE CASCADE;

ALTER TABLE purchase_tasks ADD CONSTRAINT purchase_tasks_updated_by_fk       FOREIGN KEY (updated_by_user_uuid)   REFERENCES users(entity_uuid)        ON DELETE CASCADE;

ALTER TABLE purchase_task_items ADD CONSTRAINT purchase_task_items_task_fk           FOREIGN KEY (purchase_task_id) REFERENCES purchase_tasks(id) ON DELETE CASCADE;

ALTER TABLE purchase_task_items ADD CONSTRAINT purchase_task_items_order_item_fk     FOREIGN KEY (order_item_id)    REFERENCES order_items(id)    ON DELETE SET NULL;

ALTER TABLE warehouse_records ADD CONSTRAINT warehouse_records_procurement_fk  FOREIGN KEY (procurement_task_id) REFERENCES procurement_tasks(id) ON DELETE CASCADE;

ALTER TABLE warehouse_receipts ADD CONSTRAINT warehouse_receipts_task_fk              FOREIGN KEY (purchase_task_id)      REFERENCES purchase_tasks(id) ON DELETE CASCADE;

ALTER TABLE warehouse_receipts ADD CONSTRAINT warehouse_receipts_user_fk              FOREIGN KEY (received_by_user_uuid) REFERENCES users(entity_uuid) ON DELETE CASCADE;

ALTER TABLE warehouse_shipments ADD CONSTRAINT warehouse_shipments_task_fk             FOREIGN KEY (purchase_task_id)       REFERENCES purchase_tasks(id) ON DELETE CASCADE;

ALTER TABLE warehouse_shipments ADD CONSTRAINT warehouse_shipments_user_fk             FOREIGN KEY (shipped_by_user_uuid)  REFERENCES users(entity_uuid) ON DELETE CASCADE;

ALTER TABLE shipment_tracking_events ADD CONSTRAINT shipment_tracking_events_procurement_fk  FOREIGN KEY (procurement_task_id) REFERENCES procurement_tasks(id) ON DELETE CASCADE;
