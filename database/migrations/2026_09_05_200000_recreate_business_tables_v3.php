<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v3 consolidated business-table migration.
 *
 * Strategy (per newsql.md §1.1 v3):
 *   1. Preserve original ERP field types (UUID PKs, UUID FKs, original numeric
 *      precision, original column names). No bigint↔UUID conversions.
 *   2. Append `created_at` / `updated_at` / `deleted_at` to every business
 *      table. `created_at` and `updated_at` are timestamptz(6) NOT NULL with
 *      DEFAULT CURRENT_TIMESTAMP; `deleted_at` is timestamptz(6) NULL.
 *   3. Drop and recreate the v1/v2 tables this migration supersedes:
 *        2026_09_05_130000_create_orders.php            (orders is partial — kept)
 *        2026_09_05_130001_create_order_user_overrides.php
 *        2026_09_05_130002_create_daily_stats.php
 *        2026_09_05_130003_create_exchange_rates.php
 *        2026_09_05_130004_create_influencers.php
 *        2026_09_05_130005_5_create_attachments.php
 *        2026_09_05_130005_create_influencer_domains.php
 *        2026_09_05_130006_create_invoice_orders.php
 *        2026_09_05_130007_create_invoice_items.php
 *        2026_09_05_130008_create_invoice_staff_allocations.php
 *        2026_09_05_130009_create_invoice_operation_logs.php
 *        2026_09_05_130010_create_paypal_accounts.php
 *        2026_09_05_130011_create_paypal_balance_entries.php
 *        2026_09_05_130012_create_paypal_reviews.php
 *        2026_09_05_130013_create_paypal_withdrawals.php
 *
 *      These will be removed by `php artisan migrate:rollback --step=N` after
 *      this migration lands. Until then, the rebuild happens here.
 *
 *      `orders` (2026_09_05_120000 + relax columns) is **kept as-is**; this
 *      migration only `ALTER`s to add `deleted_at` if it's missing, and
 *      ensures precision/defaults on `created_at` / `updated_at`.
 *
 *      `attachments` is rebuilt so its column list follows the ERP original
 *      (`entity_type`, `entity_id`, `file_path`, `mime`, `size_bytes`,
 *      `sha256`, `entity_uuid`) rather than the v1 short form.
 *
 *      `influencer_domains` no longer requires `influencer_id` NOT NULL FK,
 *      which was the root cause of the v2 COPY failure.
 *
 * RBAC tables (users / api_tokens / roles / permissions / role_permissions /
 * user_roles / audit_logs) created by `2026_09_04_180000_create_rbac.php`
 * are untouched.
 *
 * Data migration: see `copy-data-v3.ps1` and `.erp-sync/tables-v3.json`.
 *
 * All tables:
 *   - timestamptz(6) precision
 *   - created_at NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   - updated_at NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   - deleted_at NULL
 *   - COMMENT ON COLUMN maintained for primary columns
 */
return new class () extends Migration {
    /**
     * Convert several timestamptz columns to precision (6); set default
     * CURRENT_TIMESTAMP on `created_at`/`updated_at` (or as supplied).
     *
     * Works for both CREATE TABLE column lists (no-op; columns already there)
     * and ALTER situations. We always issue the type/default adjustment last
     * to ensure Blueprint's loose schemas converge to the same final shape.
     */
    private function fixTimestampPrecision6(string $table, array $columns, array $withDefaults = []): void
    {
        $defaults = array_flip($withDefaults);
        foreach ($columns as $col) {
            $type = DB::selectOne(
                "SELECT data_type, datetime_precision
                   FROM information_schema.columns
                  WHERE table_schema='public' AND table_name = ? AND column_name = ?",
                [$table, $col],
            );
            if (! $type) {
                continue; // column doesn't exist; skip
            }
            if ($type->data_type !== 'timestamp with time zone') {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$col} TYPE timestamptz(6) USING {$col}::timestamptz");
            } else {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$col} TYPE timestamptz(6)");
            }
            if (isset($defaults[$col])) {
                // Only attach the default if not already present.
                $hasDefault = DB::selectOne(
                    "SELECT 1 FROM pg_attrdef
                       JOIN pg_attribute ON pg_attribute.attrelid = pg_attrdef.adrelid
                                          AND pg_attribute.attnum    = pg_attrdef.adnum
                      WHERE pg_attribute.attrelid = ?::regclass
                        AND pg_attribute.attname = ?
                        AND pg_attrdef.adbin LIKE '%CURRENT_TIMESTAMP%'",
                    [$table, $col],
                );
                if (! $hasDefault) {
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$col} SET DEFAULT CURRENT_TIMESTAMP");
                }
            }
        }
    }

    /**
     * Run schema::dropIfExists on the v1/v2 tables if any exist.
     * `orders` and `attachments` are intentionally NOT dropped here:
     *   - orders — kept; we only ALTER it.
     *   - attachments — actively recreated right after via Schema::create.
     *
     * Uses raw DROP TABLE … IF EXISTS … CASCADE to bypass FK problems,
     * which `Schema::dropIfExists` would silently leave behind.
     */
    private function dropLegacyBusinessTablesIfPresent(): void
    {
        $dropOrder = [
            // payroll / projection observables
            'order_staff_performance_projection',
            'order_status_observations',
            'pending_completion_operations',
            // attach / influencer dependents
            'order_staff_allocations',
            'order_user_overrides',
            'influencer_order_links',
            'influencer_domains',
            'influencers',
            // invoice domain
            'invoice_operation_logs',
            'invoice_staff_allocations',
            'invoice_items',
            'invoice_orders',
            // paypal domain
            'paypal_withdrawals',
            'paypal_reviews',
            'paypal_balance_entries',
            'paypal_accounts',
            // system-stats
            'daily_stats',
            'exchange_rates',
            // attachments (re-created in next block)
            'attachments',
            // order_items last (UUID, simplest)
            'order_items',
        ];
        // DROP TABLE … IF EXISTS … CASCADE supports multiple names in PG ≥ 9.
        foreach (array_chunk($dropOrder, 8) as $chunk) {
            $names = implode(', ', array_map(fn ($t) => "public.{$t}", $chunk));
            DB::statement("DROP TABLE IF EXISTS {$names} CASCADE");
        }
    }

    /**
     * Drop the sequences we own. We try to drop them but ignore errors
     * because Laravel sometimes leaves them orphaned when a table is
     * dropped elsewhere.
     */
    private function dropSequencesIfPresent(array $names): void
    {
        foreach ($names as $seq) {
            DB::statement("DROP SEQUENCE IF EXISTS public.{$seq}");
        }
    }

    public function up(): void
    {
        /* ──────────────────────────────────────────────────────────────
         * 0. Drop legacy v1/v2 business tables so we can recreate cleanly.
         *    `orders` is kept (only ALTER'd); RBAC tables untouched.
         * ──────────────────────────────────────────────────────────── */
        $this->dropLegacyBusinessTablesIfPresent();

        // Drop the legacy sequences we own (v1/v2 created a few via Blueprint).
        $this->dropSequencesIfPresent([
            'daily_stats_id_seq',
            'exchange_rates_id_seq',
            'paypal_accounts_id_seq',
            'paypal_balance_entries_id_seq',
            'paypal_reviews_id_seq',
            'paypal_withdrawals_id_seq',
            'invoice_orders_id_seq',
            'invoice_items_id_seq',
            'invoice_staff_allocations_id_seq',
            'influencer_domains_id_seq',
            // 'orders_id_seq' intentionally NOT dropped — orders is kept.
            // 'attachments_id_seq' intentionally NOT dropped — used in next create.
        ]);

        // Ensure extensions required by UUID defaults / trigram search exist.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        /* ──────────────────────────────────────────────────────────────
         * 1. orders — adjust if needed.
         *    The main `orders` schema was created in 2026_09_05_120000 and
         *    relaxed in 2026_09_05_120001. Here we only:
         *      - ensure deleted_at is present (added if missing),
         *      - upgrade created_at / updated_at / order_time to
         *        timestamptz(6) with CURRENT_TIMESTAMP defaults.
         * ──────────────────────────────────────────────────────────── */
        if (! Schema::hasColumn('orders', 'deleted_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestampTz('deleted_at')->nullable()
                    ->comment('软删除时间戳；NULL=未删除');
                $table->index([DB::raw('deleted_at')], 'idx_orders_deleted_at');
            });
        }
        $this->fixTimestampPrecision6(
            'orders',
            ['created_at', 'updated_at', 'order_time', 'deleted_at'],
            ['created_at', 'updated_at'],
        );

        /* ──────────────────────────────────────────────────────────────
         * 2. attachments — recreate per ERP original.
         * ──────────────────────────────────────────────────────────── */
        Schema::create('attachments', function (Blueprint $table) {
            $table->id()->comment('主键，bigserial');
            $table->string('entity_type', 64)->nullable()->comment('所属实体类型（多态）');
            $table->unsignedBigInteger('entity_id')->nullable()->comment('所属实体主键（历史 bigint）');
            $table->string('file_path', 1000)->comment('受控存储路径；安全敏感字段');
            $table->string('mime', 200)->nullable()->comment('文件 MIME 类型');
            $table->unsignedBigInteger('size_bytes')->nullable()->comment('文件大小（字节）');
            $table->string('sha256', 64)->unique()->comment('文件 SHA-256 哈希，唯一');
            $table->uuid('entity_uuid')->unique()->default(DB::raw('gen_random_uuid()'))
                ->comment('新域稳定附件 UUID，唯一');
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('CREATE INDEX idx_attachments_entity ON attachments(entity_type, entity_id) WHERE entity_type IS NOT NULL');
        $this->fixTimestampPrecision6('attachments', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        /* ──────────────────────────────────────────────────────────────
         * 3. influencers (UUID PK) + influencer_domains (bigserial,
         *    preserve `influencer_name text` from ERP — no FK).
         * ──────────────────────────────────────────────────────────── */
        Schema::create('influencers', function (Blueprint $table) {
            $table->uuid('id')->primary()
                ->default(DB::raw('gen_random_uuid()'))
                ->comment('Influencer 标识（UUID）');
            $table->string('display_name', 200)
                ->comment('Influencer 显示名称');
            $table->string('status', 32)->default('active')
                ->comment("状态：active=正常，inactive=停用，默认 active");
            $table->jsonb('profile')->default(DB::raw("'{}'::jsonb"))
                ->comment('扩展资料 JSON，默认 {}');
            $table->integer('version')->default(1)
                ->comment('乐观锁版本，默认 1');
            $table->unsignedBigInteger('created_by_user_id')->nullable()
                ->comment('创建用户；FK → users.id（API users 已是 bigint 主键）');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('created_by_user_id', 'influencers_creator_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index('display_name', 'idx_influencers_display_name');
        });
        DB::statement("ALTER TABLE influencers ADD CONSTRAINT influencers_status_chk
                       CHECK (status IN ('active','inactive'))");
        $this->fixTimestampPrecision6('influencers', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('influencer_domains', function (Blueprint $table) {
            $table->id()->comment('主键，bigserial');
            $table->string('domain', 255)->unique()
                ->comment('来源域名，唯一');
            $table->string('influencer_name', 200)->nullable()
                ->comment('Influencer 名称（保留 ERP 原貌；不做 FK 强约束）');
            $table->boolean('confirmed')->default(false)
                ->comment('是否人工确认，默认 false');
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement("CREATE INDEX idx_influencer_domains_influencer ON influencer_domains(influencer_name)
                       WHERE influencer_name IS NOT NULL");
        $this->fixTimestampPrecision6('influencer_domains', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        /* ──────────────────────────────────────────────────────────────
         * 4. order_items (UUID), order_user_overrides (UUID),
         *    order_staff_allocations (UUID) — schema only per newsql v3.
         * ──────────────────────────────────────────────────────────── */
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary()
                ->default(DB::raw('gen_random_uuid()'))
                ->comment('主键，UUID');
            $table->uuid('order_uuid')->comment('所属订单，FK→orders.entity_uuid，级联删除');
            $table->string('sku', 100)->nullable()->comment('商品 SKU');
            $table->text('product_name')->comment('商品名称');
            $table->integer('quantity')->default(1)->comment('购买数量，默认 1');
            $table->decimal('unit_price', 14, 2)->nullable()->comment('单价');
            $table->string('currency', 8)->nullable()->comment('单价币种');
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"))->comment('商品扩展属性 JSON');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('order_uuid', 'order_items_order_uuid_fk')
                ->references('entity_uuid')->on('orders')->cascadeOnDelete();
            $table->index('order_uuid', 'idx_order_items_order_uuid');
        });
        $this->fixTimestampPrecision6('order_items', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('order_user_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary()
                ->default(DB::raw('gen_random_uuid()'))
                ->comment('主键，UUID');
            $table->string('order_key', 200)->comment('被覆盖订单的稳定身份值');
            $table->string('order_key_type', 32)->comment("身份类型: client / order / paypal");
            $table->uuid('order_uuid')->nullable()
                ->comment('解析后的正式订单外键，FK→orders.entity_uuid');
            $table->string('source_status', 64)->comment('覆盖前的原始状态');
            $table->string('status_override', 64)->comment('用户指定状态；CHECK 固定为 completed');
            $table->string('primary_staff_code', 64)->comment('指定的主负责人员工编码');
            $table->integer('version')->default(1)->comment('乐观锁版本；CHECK > 0');
            $table->unsignedBigInteger('updated_by_user_id')->comment('最后修改人外键，FK→users.id（API users 已是 bigint 主键，列名改为 *_id 以避免类型不一致）');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('order_uuid', 'order_user_overrides_order_uuid_fk')
                ->references('entity_uuid')->on('orders')->nullOnDelete();
            // users FKs always point at users.id on the API side.
            $table->foreign('updated_by_user_id', 'order_user_overrides_user_fk')
                ->references('id')->on('users');
            $table->unique(['order_key', 'order_key_type'], 'order_user_override_identity_unique');
            $table->index('order_uuid', 'idx_order_user_overrides_order_uuid');
            $table->index([DB::raw('updated_at DESC')], 'idx_order_user_overrides_updated_at');
        });
        DB::statement("ALTER TABLE order_user_overrides
                       ADD CONSTRAINT order_user_override_key_type_chk
                       CHECK (order_key_type IN ('client','order','paypal'))");
        DB::statement("ALTER TABLE order_user_overrides
                       ADD CONSTRAINT order_user_override_completed_chk
                       CHECK (status_override = 'completed')");
        DB::statement("ALTER TABLE order_user_overrides
                       ADD CONSTRAINT order_user_override_version_chk
                       CHECK (version > 0)");
        $this->fixTimestampPrecision6('order_user_overrides', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('order_staff_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary()
                ->default(DB::raw('gen_random_uuid()'))
                ->comment('主键，UUID');
            $table->uuid('order_override_id')->comment('所属覆盖记录外键，FK→order_user_overrides.id，级联删除');
            $table->string('staff_code', 64)->comment('员工编码');
            $table->string('participant_role', 32)->comment("参与角色: primary / collaborator");
            $table->decimal('share_ratio', 12, 10)->comment('绩效分摊比例；CHECK (0,1]');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('order_override_id', 'order_staff_allocation_override_fk')
                ->references('id')->on('order_user_overrides')->cascadeOnDelete();
            $table->unique(['order_override_id', 'staff_code'], 'order_staff_allocation_unique');
            $table->index('staff_code', 'idx_order_staff_allocations_staff');
        });
        DB::statement("ALTER TABLE order_staff_allocations
                       ADD CONSTRAINT order_staff_allocation_role_chk
                       CHECK (participant_role IN ('primary','collaborator'))");
        DB::statement("ALTER TABLE order_staff_allocations
                       ADD CONSTRAINT order_staff_allocation_share_chk
                       CHECK (share_ratio > 0 AND share_ratio <= 1)");
        $this->fixTimestampPrecision6('order_staff_allocations', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        /* ──────────────────────────────────────────────────────────────
         * 5. pending_completion_operations, order_status_observations,
         *    order_staff_performance_projection — schema-only per v3.
         * ──────────────────────────────────────────────────────────── */
        Schema::create('pending_completion_operations', function (Blueprint $table) {
            $table->uuid('operation_uuid')->primary()
                ->default(DB::raw('gen_random_uuid()'))
                ->comment('主键，UUID；操作稳定标识');
            $table->string('identity_type', 32)->comment('订单身份类型: client/order/paypal');
            $table->text('identity_key')->comment('订单稳定身份值');
            $table->uuid('order_uuid')->comment('目标正式订单，FK→orders.entity_uuid，级联删除；唯一');
            $table->date('business_date')->comment('归属业务日期');
            $table->string('source_status', 64)->comment("操作前状态；CHECK='pending'");
            $table->string('target_status', 64)->comment("操作后状态；CHECK='completed'");
            $table->string('target_classification', 64)->comment("目标归类；CHECK='payment_link'");
            $table->jsonb('result')->comment('操作结果快照 JSON');
            $table->timestampTz('completed_at')->comment('完成时间');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('order_uuid', 'pending_completion_order_uuid_fk')
                ->references('entity_uuid')->on('orders')->cascadeOnDelete();
            $table->unique(['identity_type', 'identity_key'], 'pending_completion_identity_unique');
            $table->unique('order_uuid', 'pending_completion_order_unique');
        });
        DB::statement("ALTER TABLE pending_completion_operations
                       ADD CONSTRAINT pending_completion_identity_type_chk
                       CHECK (identity_type IN ('client','order','paypal'))");
        DB::statement("ALTER TABLE pending_completion_operations
                       ADD CONSTRAINT pending_completion_source_chk
                       CHECK (source_status = 'pending')");
        DB::statement("ALTER TABLE pending_completion_operations
                       ADD CONSTRAINT pending_completion_target_chk
                       CHECK (target_status = 'completed')");
        DB::statement("ALTER TABLE pending_completion_operations
                       ADD CONSTRAINT pending_completion_classification_chk
                       CHECK (target_classification = 'payment_link')");
        $this->fixTimestampPrecision6('pending_completion_operations', ['created_at', 'updated_at', 'completed_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('order_status_observations', function (Blueprint $table) {
            $table->id()->comment('主键，序列');
            $table->uuid('order_uuid')->comment('正式订单，FK→orders.entity_uuid，级联删除');
            $table->string('identity_type', 32)->comment('观测使用的身份类型');
            $table->text('identity_key')->comment('观测使用的身份值');
            $table->string('source_system', 64)->default('saveb_erp')->comment('来源系统，默认 saveb_erp');
            $table->text('order_source_stable_key')->comment('来源系统稳定订单键，用于去重');
            $table->string('status', 64)->comment('上游原始状态');
            $table->string('normalized_status', 64)->comment('归一化状态');
            $table->string('classification', 64)->nullable()->comment('观测时的 Influencer 分类');
            $table->timestampTz('source_business_time')->nullable()->comment('上游业务时间');
            $table->timestampTz('observed_at')->comment('系统观测时间');
            $table->string('source', 64)->comment('触发路径 / 来源');
            $table->uuid('operation_uuid')->nullable()
                ->comment('对应完成操作，FK→pending_completion_operations.operation_uuid；唯一；删除时置 NULL');
            $table->jsonb('bounded_projection')->default(DB::raw("'{}'::jsonb"))
                ->comment('有界投影结果 JSON，默认 {}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('order_uuid', 'order_status_observations_order_uuid_fk')
                ->references('entity_uuid')->on('orders')->cascadeOnDelete();
            $table->foreign('operation_uuid', 'order_status_observations_operation_uuid_fk')
                ->references('operation_uuid')->on('pending_completion_operations')->nullOnDelete();
            $table->unique('operation_uuid', 'order_status_observation_operation_unique');
            $table->index([DB::raw('order_source_stable_key'), DB::raw('observed_at DESC')], 'idx_oso_stable_timeline');
        });
        $this->fixTimestampPrecision6('order_status_observations', ['created_at', 'updated_at', 'observed_at', 'source_business_time', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('order_staff_performance_projection', function (Blueprint $table) {
            $table->id()->comment('主键，序列');
            $table->uuid('operation_uuid')->comment('来源操作，FK→pending_completion_operations.operation_uuid，级联删除');
            $table->uuid('order_uuid')->comment('来源订单，FK→orders.entity_uuid，级联删除');
            $table->date('business_date')->comment('绩效归属日期');
            $table->string('staff_code', 64)->comment('员工编码');
            $table->decimal('share_ratio', 12, 10)->comment('员工份额；CHECK (0,1]');
            $table->decimal('orders_basis', 18, 10)->default(0)->comment('订单数投影基数');
            $table->decimal('items_basis', 18, 10)->default(0)->comment('件数投影基数');
            $table->decimal('amount_usd_basis', 18, 10)->default(0)->comment('美元金额投影基数');
            $table->decimal('commission_percent', 12, 6)->nullable()->comment('佣金比例；CHECK >= 0');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('operation_uuid', 'order_perf_proj_operation_uuid_fk')
                ->references('operation_uuid')->on('pending_completion_operations')->cascadeOnDelete();
            $table->foreign('order_uuid', 'order_perf_proj_order_uuid_fk')
                ->references('entity_uuid')->on('orders')->cascadeOnDelete();
            $table->unique(['operation_uuid', 'staff_code'], 'order_perf_proj_operation_staff_unique');
        });
        DB::statement("ALTER TABLE order_staff_performance_projection
                       ADD CONSTRAINT order_perf_proj_share_chk
                       CHECK (share_ratio > 0 AND share_ratio <= 1)");
        DB::statement("ALTER TABLE order_staff_performance_projection
                       ADD CONSTRAINT order_perf_proj_commission_chk
                       CHECK (commission_percent IS NULL OR commission_percent >= 0)");
        $this->fixTimestampPrecision6('order_staff_performance_projection', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        /* ──────────────────────────────────────────────────────────────
         * 6. invoice_orders + invoice_items + invoice_staff_allocations
         *    + invoice_operation_logs (UUID)
         * ──────────────────────────────────────────────────────────── */
        Schema::create('invoice_orders', function (Blueprint $table) {
            $table->id()->comment('主键，bigserial');
            $table->text('legacy_id')->nullable()->comment('旧系统记录标识（UQ）');
            $table->text('order_number')->comment('Invoice 订单号，唯一');
            $table->date('invoice_date')->comment('Invoice 开具日期');
            $table->text('customer_full_name')->nullable()->comment('客户全名；敏感字段');
            $table->text('customer_email')->nullable()->comment('客户邮箱；敏感字段');
            $table->string('phone_number', 64)->nullable()->comment('客户电话；敏感字段');
            $table->string('country', 128)->nullable()->comment('国家/地区');
            $table->string('country_source', 64)->nullable()->comment('国家识别来源');
            $table->text('address')->nullable()->comment('地址；敏感字段');
            $table->text('invoice_link')->nullable()->comment('Invoice 链接');
            $table->string('invoice_status', 64)->comment('Invoice 状态');
            $table->boolean('expedited_shipping')->default(false)->comment('是否加急运输');
            $table->decimal('fixed_discount', 14, 2)->nullable()->comment('固定金额折扣');
            $table->decimal('percentage_discount', 14, 2)->nullable()->comment('百分比折扣');
            $table->string('gift_box', 16)->default('Has')->comment("礼盒状态：Has/None");
            $table->decimal('amount_usd', 14, 2)->comment('Invoice 美元总金额');
            $table->text('recipient_paypal')->nullable()->comment('收款 PayPal；敏感字段');
            $table->unsignedBigInteger('created_by')->nullable()
                ->comment('创建用户（ERP bigint 字段名）；FK→users.id');
            $table->jsonb('raw')->nullable()->comment('OCR / 导入原始快照');
            $table->date('order_date')->nullable()->comment('订单业务日期');
            $table->unsignedBigInteger('invoice_screenshot_attachment_id')->nullable()
                ->comment('Invoice 截图附件外键，FK→attachments.id');
            $table->uuid('entity_uuid')->unique()->default(DB::raw('gen_random_uuid()'))
                ->comment('新域稳定 UUID');
            $table->integer('version')->default(1)->comment('乐观锁版本');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('created_by', 'invoice_orders_creator_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('invoice_screenshot_attachment_id', 'invoice_orders_attachment_fk')
                ->references('id')->on('attachments')->nullOnDelete();
            $table->unique('order_number', 'invoice_orders_order_number_unique');
            $table->unique('entity_uuid', 'invoice_orders_entity_uuid_unique');
            $table->index([DB::raw('invoice_date DESC')], 'idx_invoice_orders_date');
            $table->index('entity_uuid', 'idx_invoice_orders_entity_uuid');
        });
        $this->fixTimestampPrecision6('invoice_orders', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id()->comment('主键，bigserial');
            $table->unsignedBigInteger('invoice_id')->comment('所属 Invoice，FK→invoice_orders.id，级联删除');
            $table->text('product_name')->nullable()->comment('商品名称');
            $table->text('description')->nullable()->comment('商品说明');
            $table->integer('quantity')->default(1)->comment('数量');
            $table->decimal('price', 14, 2)->nullable()->comment('单价或行金额');
            $table->text('notes')->nullable()->comment('备注');
            $table->unsignedBigInteger('image_attachment_id')->nullable()
                ->comment('商品图片附件外键，FK→attachments.id');
            $table->uuid('entity_uuid')->unique()->default(DB::raw('gen_random_uuid()'))
                ->comment('新域稳定 UUID');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('invoice_id', 'invoice_items_invoice_id_fk')
                ->references('id')->on('invoice_orders')->cascadeOnDelete();
            $table->foreign('image_attachment_id', 'invoice_items_image_attachment_fk')
                ->references('id')->on('attachments')->nullOnDelete();
            $table->index('invoice_id', 'idx_invoice_items_invoice_id');
        });
        $this->fixTimestampPrecision6('invoice_items', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('invoice_staff_allocations', function (Blueprint $table) {
            $table->id()->comment('主键，bigserial');
            $table->unsignedBigInteger('invoice_id')->comment('所属 Invoice 外键，级联删除');
            $table->string('staff_code', 64)->comment('员工编码');
            $table->decimal('commission_percent', 5, 2)->nullable()->comment('佣金百分比');
            $table->decimal('share_ratio', 6, 4)->default(1)->comment('分摊比例，默认 1（100%）');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('invoice_id', 'invoice_staff_alloc_invoice_fk')
                ->references('id')->on('invoice_orders')->cascadeOnDelete();
            $table->unique(['invoice_id', 'staff_code'], 'invoice_staff_alloc_unique');
            $table->index('invoice_id', 'idx_invoice_staff_alloc_invoice_id');
        });
        $this->fixTimestampPrecision6('invoice_staff_allocations', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('invoice_operation_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()
                ->default(DB::raw('gen_random_uuid()'))
                ->comment('主键，UUID');
            $table->uuid('invoice_uuid')->comment('Invoice 外键，FK→invoice_orders.entity_uuid，级联删除');
            $table->string('action', 64)->comment('操作动作');
            $table->jsonb('before')->nullable()->comment('变更前快照 JSON');
            $table->jsonb('after')->nullable()->comment('变更后快照 JSON');
            $table->unsignedBigInteger('actor_user_id')->comment('操作者，FK→users.id（API users 已是 bigint 主键）');
            $table->string('request_id', 64)->nullable()->comment('请求追踪 ID');
            $table->string('source', 32)->default('api')->comment('操作来源，默认 api');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('invoice_uuid', 'invoice_operation_logs_invoice_uuid_fk')
                ->references('entity_uuid')->on('invoice_orders')->cascadeOnDelete();
            // users FK references users.id (API users table has no entity_uuid).
            $table->foreign('actor_user_id', 'invoice_operation_logs_user_fk')
                ->references('id')->on('users');
            $table->index([DB::raw('invoice_uuid'), DB::raw('created_at DESC')], 'idx_invoice_operation_invoice_time');
        });
        $this->fixTimestampPrecision6('invoice_operation_logs', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        /* ──────────────────────────────────────────────────────────────
         * 7. paypal_* tables — preserve ERP original column names
         *    (`entered_by`, `created_by` rather than the v1 `_user_id` suffixes).
         * ──────────────────────────────────────────────────────────── */
        Schema::create('paypal_accounts', function (Blueprint $table) {
            $table->id()->comment('主键，bigserial');
            $table->text('email')->comment('PayPal 账号邮箱，唯一；敏感字段');
            $table->text('account_name')->nullable()->comment('账号显示名称');
            $table->date('added_date')->nullable()->comment('账号添加日期');
            $table->boolean('active')->default(true)->comment('是否启用，默认 true');
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"))->comment('扩展元数据 JSON');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique('email', 'paypal_accounts_email_unique');
        });
        $this->fixTimestampPrecision6('paypal_accounts', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('paypal_balance_entries', function (Blueprint $table) {
            $table->id()->comment('主键，bigserial');
            $table->unsignedBigInteger('account_id')->comment('所属账号外键，FK→paypal_accounts.id，级联删除');
            $table->decimal('balance', 14, 2)->comment('当次录入余额');
            $table->unsignedBigInteger('entered_by')->nullable()
                ->comment('录入人，FK→users.id；保留 ERP 字段名（v3 与 ERP 一致）');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('account_id', 'paypal_balance_account_fk')
                ->references('id')->on('paypal_accounts')->cascadeOnDelete();
            $table->foreign('entered_by', 'paypal_balance_user_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index('account_id', 'idx_paypal_balance_account_id');
        });
        $this->fixTimestampPrecision6('paypal_balance_entries', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('paypal_reviews', function (Blueprint $table) {
            $table->id()->comment('主键，bigserial');
            $table->unsignedBigInteger('account_id')->comment('所属账号外键，FK→paypal_accounts.id，级联删除');
            $table->integer('review_count')->comment('当次录入 Review 数量');
            $table->unsignedBigInteger('entered_by')->nullable()
                ->comment('录入人，FK→users.id；保留 ERP 字段名');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('account_id', 'paypal_reviews_account_fk')
                ->references('id')->on('paypal_accounts')->cascadeOnDelete();
            $table->foreign('entered_by', 'paypal_reviews_user_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index('account_id', 'idx_paypal_reviews_account_id');
        });
        $this->fixTimestampPrecision6('paypal_reviews', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('paypal_withdrawals', function (Blueprint $table) {
            $table->id()->comment('主键，bigserial');
            $table->unsignedBigInteger('account_id')->comment('所属账号外键，FK→paypal_accounts.id，级联删除');
            $table->decimal('amount', 14, 2)->comment('提现金额');
            $table->text('source')->nullable()->comment('提现来源 / 备注');
            $table->date('withdrawn_at')->comment('提现业务日期');
            $table->unsignedBigInteger('created_by')->nullable()
                ->comment('创建人，FK→users.id；保留 ERP 字段名');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('account_id', 'paypal_withdrawals_account_fk')
                ->references('id')->on('paypal_accounts')->cascadeOnDelete();
            $table->foreign('created_by', 'paypal_withdrawals_user_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index('account_id', 'idx_paypal_withdrawals_account_id');
        });
        $this->fixTimestampPrecision6('paypal_withdrawals', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        /* ──────────────────────────────────────────────────────────────
         * 8. daily_stats + exchange_rates (already existed but rebuilt).
         * ──────────────────────────────────────────────────────────── */
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id()->comment('主键，序列');
            $table->date('stat_date')->comment('业务统计日');
            $table->string('channel', 64)->comment('渠道维度');
            $table->string('staff_code', 64)->default('')->comment('员工维度；空串表示汇总口径');
            $table->decimal('orders_count', 18, 10)->default(0)->comment('按份额计算的订单数');
            $table->decimal('items_count', 18, 10)->default(0)->comment('按份额计算的件数');
            $table->decimal('usd_amount', 18, 10)->default(0)->comment('按份额计算的美元金额');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['stat_date', 'channel', 'staff_code'], 'daily_stats_unique');
        });
        $this->fixTimestampPrecision6('daily_stats', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id()->comment('主键，序列');
            $table->string('currency', 8)->comment('币种代码');
            $table->decimal('rate_to_usd', 18, 8)->comment('兑美元汇率');
            $table->date('effective_date')->comment('汇率生效日期');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['currency', 'effective_date'], 'exchange_rates_unique');
        });
        $this->fixTimestampPrecision6('exchange_rates', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        /* ──────────────────────────────────────────────────────────────
         * 9. influencer_order_links + site_classification_reclassifications
         *    (downstream; v3 only creates the schema, no data).
         * ──────────────────────────────────────────────────────────── */
        Schema::create('influencer_order_links', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'))
                ->comment('主键，UUID');
            $table->uuid('influencer_id')->comment('Influencer，FK→influencers.id，级联删除');
            $table->uuid('order_uuid')->comment('订单，FK→orders.entity_uuid，级联删除');
            $table->string('source', 32)->default('manual')->comment('关联来源：manual/auto，默认 manual');
            $table->unsignedBigInteger('created_by_user_id')->nullable()->comment('创建用户，FK→users.id，删除时置 NULL');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('influencer_id', 'influencer_links_influencer_fk')
                ->references('id')->on('influencers')->cascadeOnDelete();
            $table->foreign('order_uuid', 'influencer_links_order_fk')
                ->references('entity_uuid')->on('orders')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'influencer_links_user_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->unique(['influencer_id', 'order_uuid'], 'influencer_order_link_unique');
        });
        $this->fixTimestampPrecision6('influencer_order_links', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);

        Schema::create('site_classification_reclassifications', function (Blueprint $table) {
            $table->string('release_id', 64)->comment('发布/修复批次标识（复合主键第一部分）');
            $table->text('order_id')->comment('ERP 订单标识（复合主键第二部分）');
            $table->text('source_domain')->comment('来源域名');
            $table->string('previous_classification', 64)->comment('重分类前归类');
            $table->text('previous_influencer_name')->nullable()->comment('重分类前 Influencer');
            $table->string('target_classification', 64)->comment('重分类后归类');
            $table->text('target_influencer_name')->nullable()->comment('重分类后 Influencer');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->primary(['release_id', 'order_id']);
            $table->index('order_id', 'idx_site_classification_order_id');
        });
        $this->fixTimestampPrecision6('site_classification_reclassifications', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
    }

    public function down(): void
    {
        // Drop the v3 business tables in reverse dependency order.
        $this->dropLegacyBusinessTablesIfPresent();

        // Drop orphan sequences we own (orders_id_seq intentionally omitted).
        $this->dropSequencesIfPresent([
            'daily_stats_id_seq',
            'exchange_rates_id_seq',
            'paypal_accounts_id_seq',
            'paypal_balance_entries_id_seq',
            'paypal_reviews_id_seq',
            'paypal_withdrawals_id_seq',
            'invoice_orders_id_seq',
            'invoice_items_id_seq',
            'invoice_staff_allocations_id_seq',
            'influencer_domains_id_seq',
        ]);

        // orders deleted_at (if we added it) goes away with the table; if the
        // existing v1/v2 `orders` migration dropped it via cascading ALTER,
        // we're idempotent because we used Schema::hasColumn() guard.
    }
};
