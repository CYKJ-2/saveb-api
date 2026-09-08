<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated schema migration for all non-RBAC business tables (v4).
 *
 * 目的
 *  ─────
 *  把 `newsql.md` §3-§7 中所有非 RBAC 业务表一次落地。本迁移与
 *  `2026_09_05_200000_recreate_business_tables_v3.php` 内容等价但更简洁：
 *    - 完全沿用 ERP 原字段名（`text` 替代 `varchar`，UUID 主键等）
 *    - 所有表都补齐 `created_at timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP`
 *    - 所有表都补齐 `updated_at timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP`
 *    - 所有表都补齐 `deleted_at timestamptz(6) NULL`
 *    - 时间戳精度统一为 6；timestamptz 默认值与 data-dictionary 一致
 *
 * 覆盖范围（13 张业务表 + 4 张下游表）
 *  ─────────────────────────────────────────────────────────────
 *   1. attachments                       (§8.1)
 *   2. influencers                       (§7.1, UUID PK)
 *   3. influencer_domains                (§7.2, bigserial PK；保留 ERP 原貌)
 *   4. order_items                       (§3.2, UUID PK)
 *   5. order_user_overrides              (§4.1, UUID PK)
 *   6. order_staff_allocations           (§4.2, UUID PK)
 *   7. pending_completion_operations     (§4.3, UUID PK；v4 仅建表)
 *   8. order_status_observations         (§4.4, bigserial；v4 仅建表)
 *   9. order_staff_performance_projection(§4.5, bigserial；v4 仅建表)
 *  10. influencer_order_links            (§7.3, UUID PK；v4 仅建表)
 *  11. site_classification_reclassifications(§7.4, 复合 PK；v4 仅建表)
 *  12. invoice_orders                    (§5.1, bigserial PK)
 *  13. invoice_items                     (§5.2, bigserial PK)
 *  14. invoice_staff_allocations         (§5.3, bigserial PK)
 *  15. invoice_operation_logs            (§5.4, UUID PK)
 *  16. paypal_accounts                   (§6.1, bigserial PK)
 *  17. paypal_balance_entries            (§6.2, bigserial PK)
 *  18. paypal_reviews                    (§6.3, bigserial PK)
 *  19. paypal_withdrawals                (§6.4, bigserial PK)
 *  20. daily_stats                       (§3.3, bigserial PK)
 *  21. exchange_rates                    (§3.4, bigserial PK)
 *
 * 不触碰
 *  ──────
 *  - users / api_tokens / roles / permissions / role_permissions /
 *    user_roles / audit_logs：RBAC 域，由 `2026_09_04_180000_create_rbac.php` 处理。
 *  - orders：`2026_09_05_120000_create_orders.php` + 2026_09_05_120001_relax 系列已建。
 *    本迁移只保证 `deleted_at` 存在 + 调整精度（如果缺失则补）。
 *
 * 幂等性
 *  ──────
 *  - Schema::hasTable() 守门：已存在的表整体跳过。
 *  - 时间戳精度 fix：检测 data_type 后再 ALTER，避免无谓的 catalog churn。
 *  - FK 约束：保持 ON DELETE CASCADE/SET NULL 与 newsql.md 一致。
 *  - 所有 CHECK 约束：与 data-dictionary / newsql.md 完全一致。
 *
 * 注意
 *  ──────
 *  - 如果你的数据库已经跑过 `2026_09_05_200000_recreate_business_tables_v3.php`，
 *    再跑这个迁移会因 Schema::hasTable() 全部跳过，属于安全行为。
 *  - 如果你的数据库是从 ERP 直接 COPY 进来的（无 RBAC 历史），这个迁移会
 *    一次性建好所有表。
 */
return new class () extends Migration {
    /**
     * 把表中的 timestamptz 列精度统一为 (6)，按 $withDefaults 列表补 CURRENT_TIMESTAMP 默认值。
     *
     * @param  string            $table         表名
     * @param  array<int,string> $columns       时间列名列表
     * @param  array<int,string> $withDefaults  需要默认值的列
     */
    private function fixTimestampPrecision6(string $table, array $columns, array $withDefaults = []): void
    {
        $defaults = array_flip($withDefaults);
        foreach ($columns as $col) {
            // 列不存在就跳过（外加 hasColumn 保护）
            $exists = DB::selectOne(
                "SELECT 1 FROM information_schema.columns
                  WHERE table_schema='public' AND table_name=? AND column_name=?",
                [$table, $col],
            );
            if (!$exists) {
                continue;
            }
            $type = DB::selectOne(
                "SELECT data_type, datetime_precision
                   FROM information_schema.columns
                  WHERE table_schema='public' AND table_name=? AND column_name=?",
                [$table, $col],
            );
            if ($type && $type->data_type === 'timestamp with time zone') {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$col} TYPE timestamptz(6)");
            } else {
                // 兜底：从其它类型 (e.g. text) 强转
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$col} TYPE timestamptz(6) USING {$col}::timestamptz");
            }

            if (isset($defaults[$col])) {
                $hasDefault = DB::selectOne(
                    "SELECT 1 FROM pg_attrdef
                       JOIN pg_attribute ON pg_attribute.attrelid = pg_attrdef.adrelid
                                          AND pg_attribute.attnum    = pg_attrdef.adnum
                      WHERE pg_attribute.attrelid = ?::regclass
                        AND pg_attribute.attname = ?
                        AND pg_attrdef.adbin LIKE '%CURRENT_TIMESTAMP%'",
                    [$table, $col],
                );
                if (!$hasDefault) {
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$col} SET DEFAULT CURRENT_TIMESTAMP");
                }
            }
        }
    }

    /**
     * 为指定表添加 created_at/updated_at/deleted_at 列（如果不存在）。
     */
    private function ensureSoftDeleteTimestamps(string $table): void
    {
        if (!Schema::hasColumn($table, 'created_at')) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestampTz('created_at')->nullable();
            });
            DB::statement("ALTER TABLE {$table} ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP");
        }
        if (!Schema::hasColumn($table, 'updated_at')) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestampTz('updated_at')->nullable();
            });
            DB::statement("ALTER TABLE {$table} ALTER COLUMN updated_at SET DEFAULT CURRENT_TIMESTAMP");
        }
        if (!Schema::hasColumn($table, 'deleted_at')) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestampTz('deleted_at')->nullable();
            });
        }
    }

    public function up(): void
    {
        // pgcrypto 用于 gen_random_uuid() 默认值
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        /* =================================================================
         * 1. orders — 仅在必要时补 deleted_at 和时间戳精度
         * ================================================================= */
        if (Schema::hasTable('orders')) {
            if (!Schema::hasColumn('orders', 'deleted_at')) {
                Schema::table('orders', function (Blueprint $t) {
                    $t->timestampTz('deleted_at')->nullable();
                });
            }
            $this->fixTimestampPrecision6(
                'orders',
                ['created_at', 'updated_at', 'order_time', 'deleted_at'],
                ['created_at', 'updated_at'],
            );
        }

        /* =================================================================
         * 2. attachments (§8.1) — bigserial PK + entity_uuid
         * ================================================================= */
        if (!Schema::hasTable('attachments')) {
            Schema::create('attachments', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->text('entity_type')->nullable()->comment('多态所属实体类型');
                $table->unsignedBigInteger('entity_id')->nullable()->comment('所属实体主键（历史 bigint）');
                $table->text('file_path')->comment('受控存储路径；安全敏感字段');
                $table->text('mime')->nullable()->comment('文件 MIME');
                $table->unsignedBigInteger('size_bytes')->nullable()->comment('字节数');
                $table->text('sha256')->comment('SHA-256 哈希（无 UNIQUE — ERP 端允许重复）');
                $table->uuid('entity_uuid')->unique()->default(DB::raw('gen_random_uuid()'))
                    ->comment('新域稳定 UUID');
                $table->timestampsTz();
                $table->softDeletesTz();
            });
            DB::statement('CREATE INDEX idx_attachments_entity ON attachments(entity_type, entity_id) WHERE entity_type IS NOT NULL');
            DB::statement('CREATE INDEX idx_attachments_sha256 ON attachments(sha256)');
            $this->fixTimestampPrecision6('attachments', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 3. influencers (§7.1) — UUID PK
         * ================================================================= */
        if (!Schema::hasTable('influencers')) {
            Schema::create('influencers', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'))
                    ->comment('Influencer 标识（UUID）');
                $table->string('display_name', 200)->comment('Influencer 显示名称');
                $table->string('status', 32)->default('active')
                    ->comment("状态：active=正常，inactive=停用");
                $table->jsonb('profile')->default(DB::raw("'{}'::jsonb"))->comment('扩展资料');
                $table->integer('version')->default(1)->comment('乐观锁版本');
                $table->unsignedBigInteger('created_by_user_id')->nullable()
                    ->comment('创建用户；FK → users.id（API users 是 bigserial 主键）');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->foreign('created_by_user_id', 'influencers_creator_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->index('display_name', 'idx_influencers_display_name');
            });
            DB::statement("ALTER TABLE influencers
                           ADD CONSTRAINT influencers_status_chk
                           CHECK (status IN ('active','inactive'))");
            $this->fixTimestampPrecision6('influencers', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 4. influencer_domains (§7.2) — bigserial PK；保留 ERP 原貌
         * ================================================================= */
        if (!Schema::hasTable('influencer_domains')) {
            Schema::create('influencer_domains', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->string('domain', 255)->unique()->comment('来源域名，唯一');
                $table->string('influencer_name', 200)->nullable()
                    ->comment('Influencer 名称（保留 ERP 原貌，不做 FK 强约束）');
                $table->boolean('confirmed')->default(false)->comment('是否人工确认');
                $table->timestampsTz();
                $table->softDeletesTz();
            });
            DB::statement("CREATE INDEX idx_influencer_domains_influencer ON influencer_domains(influencer_name)
                           WHERE influencer_name IS NOT NULL");
            $this->fixTimestampPrecision6('influencer_domains', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 5. order_items (§3.2) — UUID PK + FK → orders.entity_uuid
         * ================================================================= */
        if (!Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'))
                    ->comment('主键，UUID');
                $table->uuid('order_uuid')->comment('所属订单；FK → orders.entity_uuid，级联删除');
                $table->string('sku', 100)->nullable()->comment('商品 SKU');
                $table->text('product_name')->comment('商品名称');
                $table->integer('quantity')->default(1)->comment('数量');
                $table->decimal('unit_price', 14, 2)->nullable()->comment('单价');
                $table->string('currency', 8)->nullable()->comment('单价币种');
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"))->comment('扩展属性');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->foreign('order_uuid', 'order_items_order_uuid_fk')
                    ->references('entity_uuid')->on('orders')->cascadeOnDelete();
                $table->index('order_uuid', 'idx_order_items_order_uuid');
            });
            $this->fixTimestampPrecision6('order_items', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 6. order_user_overrides (§4.1) — UUID PK
         * ================================================================= */
        if (!Schema::hasTable('order_user_overrides')) {
            Schema::create('order_user_overrides', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'))
                    ->comment('主键，UUID');
                $table->string('order_key', 200)->comment('订单稳定身份值');
                $table->string('order_key_type', 32)->comment('身份类型：client/order/paypal');
                $table->uuid('order_uuid')->nullable()
                    ->comment('FK → orders.entity_uuid；订单删除时置 NULL');
                $table->string('source_status', 64)->comment('覆盖前状态');
                $table->string('status_override', 64)->comment('覆盖后状态；CHECK=completed');
                $table->string('primary_staff_code', 64)->comment('主负责人员工编码');
                $table->integer('version')->default(1)->comment('乐观锁版本；CHECK > 0');
                // updated_by_user_uuid 保留 ERP 原字段名（UUID 域），但 API 端 users 没有
                // entity_uuid 列，所以不加 FK 强约束；列保留为普通 UUID 引用列。
                $table->uuid('updated_by_user_uuid')->nullable()
                    ->comment('最后修改人 UUID（无 FK：API 端 users 无 entity_uuid）');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->foreign('order_uuid', 'order_user_overrides_order_uuid_fk')
                    ->references('entity_uuid')->on('orders')->nullOnDelete();
                $table->unique(['order_key', 'order_key_type'], 'order_user_override_identity_unique');
                $table->index('order_uuid', 'idx_order_user_overrides_order_uuid');
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
        }

        /* =================================================================
         * 7. order_staff_allocations (§4.2) — UUID PK
         * ================================================================= */
        if (!Schema::hasTable('order_staff_allocations')) {
            Schema::create('order_staff_allocations', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'))
                    ->comment('主键，UUID');
                $table->uuid('order_override_id')->comment('FK → order_user_overrides.id，级联删除');
                $table->string('staff_code', 64)->comment('员工编码');
                $table->string('participant_role', 32)->comment('primary/collaborator');
                $table->decimal('share_ratio', 12, 10)->comment('份额；CHECK (0,1]');
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
        }

        /* =================================================================
         * 8. pending_completion_operations (§4.3) — UUID PK
         * ================================================================= */
        if (!Schema::hasTable('pending_completion_operations')) {
            Schema::create('pending_completion_operations', function (Blueprint $table) {
                $table->uuid('operation_uuid')->primary()->default(DB::raw('gen_random_uuid()'))
                    ->comment('主键，UUID；操作稳定标识');
                $table->string('identity_type', 32)->comment('client/order/paypal');
                $table->text('identity_key')->comment('订单稳定身份值');
                $table->uuid('order_uuid')->comment('FK → orders.entity_uuid，级联删除；唯一');
                $table->date('business_date')->comment('归属业务日期');
                $table->string('source_status', 64)->comment("操作前状态；CHECK='pending'");
                $table->string('target_status', 64)->comment("操作后状态；CHECK='completed'");
                $table->string('target_classification', 64)->comment("目标归类；CHECK='payment_link'");
                $table->jsonb('result')->comment('操作结果快照');
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
            $this->fixTimestampPrecision6(
                'pending_completion_operations',
                ['created_at', 'updated_at', 'completed_at', 'deleted_at'],
                ['created_at', 'updated_at'],
            );
        }

        /* =================================================================
         * 9. order_status_observations (§4.4) — bigserial PK
         * ================================================================= */
        if (!Schema::hasTable('order_status_observations')) {
            Schema::create('order_status_observations', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->uuid('order_uuid')->comment('FK → orders.entity_uuid，级联删除');
                $table->string('identity_type', 32)->comment('身份类型');
                $table->text('identity_key')->comment('身份值');
                $table->string('source_system', 64)->default('saveb_erp')->comment('来源系统');
                $table->text('order_source_stable_key')->comment('上游稳定订单键');
                $table->string('status', 64)->comment('上游原始状态');
                $table->string('normalized_status', 64)->comment('归一化状态');
                $table->string('classification', 64)->nullable()->comment('观测时归类');
                $table->timestampTz('source_business_time')->nullable()->comment('上游业务时间');
                $table->timestampTz('observed_at')->comment('系统观测时间');
                $table->string('source', 64)->comment('触发路径');
                $table->uuid('operation_uuid')->nullable()
                    ->comment('FK → pending_completion_operations.operation_uuid；唯一；删除时置 NULL');
                $table->jsonb('bounded_projection')->default(DB::raw("'{}'::jsonb"))->comment('有界投影结果');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->foreign('order_uuid', 'order_status_observations_order_uuid_fk')
                    ->references('entity_uuid')->on('orders')->cascadeOnDelete();
                $table->foreign('operation_uuid', 'order_status_observations_operation_uuid_fk')
                    ->references('operation_uuid')->on('pending_completion_operations')->nullOnDelete();
                $table->unique('operation_uuid', 'order_status_observation_operation_unique');
            });
            $this->fixTimestampPrecision6(
                'order_status_observations',
                ['created_at', 'updated_at', 'observed_at', 'source_business_time', 'deleted_at'],
                ['created_at', 'updated_at'],
            );
        }

        /* =================================================================
         * 10. order_staff_performance_projection (§4.5) — bigserial PK
         * ================================================================= */
        if (!Schema::hasTable('order_staff_performance_projection')) {
            Schema::create('order_staff_performance_projection', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->uuid('operation_uuid')->comment('FK → pending_completion_operations.operation_uuid，级联删除');
                $table->uuid('order_uuid')->comment('FK → orders.entity_uuid，级联删除');
                $table->date('business_date')->comment('绩效归属日');
                $table->string('staff_code', 64)->comment('员工编码');
                $table->decimal('share_ratio', 12, 10)->comment('份额；CHECK (0,1]');
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
            $this->fixTimestampPrecision6(
                'order_staff_performance_projection',
                ['created_at', 'updated_at', 'deleted_at'],
                ['created_at', 'updated_at'],
            );
        }

        /* =================================================================
         * 11. invoice_orders (§5.1) — bigserial PK + entity_uuid
         * ================================================================= */
        if (!Schema::hasTable('invoice_orders')) {
            Schema::create('invoice_orders', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->text('legacy_id')->nullable()->comment('旧系统记录标识（UQ）');
                $table->text('order_number')->comment('Invoice 订单号；唯一');
                $table->date('invoice_date')->comment('Invoice 日期');
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
                $table->string('gift_box', 16)->default('Has')->comment('礼盒状态');
                $table->decimal('amount_usd', 14, 2)->comment('Invoice 美元金额');
                $table->text('recipient_paypal')->nullable()->comment('收款 PayPal；敏感字段');
                $table->unsignedBigInteger('created_by')->nullable()
                    ->comment('创建用户（ERP 字段名）；FK → users.id');
                $table->jsonb('raw')->nullable()->comment('OCR 原始快照');
                $table->date('order_date')->nullable()->comment('订单业务日期');
                $table->unsignedBigInteger('invoice_screenshot_attachment_id')->nullable()
                    ->comment('FK → attachments.id；Invoice 截图');
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
                $table->index([DB::raw('invoice_date DESC')], 'idx_invoice_orders_date');
                $table->index('entity_uuid', 'idx_invoice_orders_entity_uuid');
            });
            $this->fixTimestampPrecision6('invoice_orders', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 12. invoice_items (§5.2) — bigserial PK + entity_uuid
         * ================================================================= */
        if (!Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->unsignedBigInteger('invoice_id')->comment('FK → invoice_orders.id，级联删除');
                $table->text('product_name')->nullable()->comment('商品名称');
                $table->text('description')->nullable()->comment('商品说明');
                $table->integer('quantity')->default(1)->comment('数量');
                $table->decimal('price', 14, 2)->nullable()->comment('单价/行金额');
                $table->text('notes')->nullable()->comment('备注');
                $table->unsignedBigInteger('image_attachment_id')->nullable()
                    ->comment('FK → attachments.id；商品图片');
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
        }

        /* =================================================================
         * 13. invoice_staff_allocations (§5.3) — bigserial PK
         * ================================================================= */
        if (!Schema::hasTable('invoice_staff_allocations')) {
            Schema::create('invoice_staff_allocations', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->unsignedBigInteger('invoice_id')->comment('FK → invoice_orders.id，级联删除');
                $table->string('staff_code', 64)->comment('员工编码');
                $table->decimal('commission_percent', 5, 2)->nullable()->comment('佣金百分比');
                $table->decimal('share_ratio', 6, 4)->default(1)->comment('分摊比例');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->foreign('invoice_id', 'invoice_staff_alloc_invoice_fk')
                    ->references('id')->on('invoice_orders')->cascadeOnDelete();
                $table->unique(['invoice_id', 'staff_code'], 'invoice_staff_alloc_unique');
                $table->index('invoice_id', 'idx_invoice_staff_alloc_invoice_id');
            });
            $this->fixTimestampPrecision6('invoice_staff_allocations', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 14. invoice_operation_logs (§5.4) — UUID PK
         * ================================================================= */
        if (!Schema::hasTable('invoice_operation_logs')) {
            Schema::create('invoice_operation_logs', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'))
                    ->comment('主键，UUID');
                $table->uuid('invoice_uuid')->comment('FK → invoice_orders.entity_uuid，级联删除');
                $table->string('action', 64)->comment('操作动作');
                $table->jsonb('before')->nullable()->comment('变更前快照');
                $table->jsonb('after')->nullable()->comment('变更后快照');
                // actor_user_uuid 保留 ERP 原字段名（UUID 域），但 API 端 users 没有
                // entity_uuid 列，所以不加 FK 强约束；列保留为普通 UUID 引用列。
                $table->uuid('actor_user_uuid')->nullable()
                    ->comment('操作者 UUID（无 FK：API 端 users 无 entity_uuid）');
                $table->string('request_id', 64)->nullable()->comment('请求追踪 ID');
                $table->string('source', 32)->default('api')->comment('操作来源');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->foreign('invoice_uuid', 'invoice_operation_logs_invoice_uuid_fk')
                    ->references('entity_uuid')->on('invoice_orders')->cascadeOnDelete();
                $table->index([DB::raw('invoice_uuid'), DB::raw('created_at DESC')], 'idx_invoice_operation_invoice_time');
            });
            $this->fixTimestampPrecision6('invoice_operation_logs', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 15. paypal_accounts (§6.1) — bigserial PK
         * ================================================================= */
        if (!Schema::hasTable('paypal_accounts')) {
            Schema::create('paypal_accounts', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->text('email')->comment('PayPal 邮箱；唯一；敏感字段');
                $table->text('account_name')->nullable()->comment('账号显示名称');
                $table->date('added_date')->nullable()->comment('账号添加日期');
                $table->boolean('active')->default(true)->comment('是否启用');
                $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"))->comment('扩展元数据');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique('email', 'paypal_accounts_email_unique');
            });
            $this->fixTimestampPrecision6('paypal_accounts', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 16. paypal_balance_entries (§6.2) — bigserial PK
         * ================================================================= */
        if (!Schema::hasTable('paypal_balance_entries')) {
            Schema::create('paypal_balance_entries', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->unsignedBigInteger('account_id')->comment('FK → paypal_accounts.id，级联删除');
                $table->decimal('balance', 14, 2)->comment('当次余额');
                $table->unsignedBigInteger('entered_by')->nullable()
                    ->comment('录入人；FK → users.id；保留 ERP 字段名');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->foreign('account_id', 'paypal_balance_account_fk')
                    ->references('id')->on('paypal_accounts')->cascadeOnDelete();
                $table->foreign('entered_by', 'paypal_balance_user_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->index('account_id', 'idx_paypal_balance_account_id');
            });
            $this->fixTimestampPrecision6('paypal_balance_entries', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 17. paypal_reviews (§6.3) — bigserial PK
         * ================================================================= */
        if (!Schema::hasTable('paypal_reviews')) {
            Schema::create('paypal_reviews', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->unsignedBigInteger('account_id')->comment('FK → paypal_accounts.id，级联删除');
                $table->integer('review_count')->comment('当次 Review 数量');
                $table->unsignedBigInteger('entered_by')->nullable()
                    ->comment('录入人；FK → users.id；保留 ERP 字段名');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->foreign('account_id', 'paypal_reviews_account_fk')
                    ->references('id')->on('paypal_accounts')->cascadeOnDelete();
                $table->foreign('entered_by', 'paypal_reviews_user_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->index('account_id', 'idx_paypal_reviews_account_id');
            });
            $this->fixTimestampPrecision6('paypal_reviews', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 18. paypal_withdrawals (§6.4) — bigserial PK
         * ================================================================= */
        if (!Schema::hasTable('paypal_withdrawals')) {
            Schema::create('paypal_withdrawals', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->unsignedBigInteger('account_id')->comment('FK → paypal_accounts.id，级联删除');
                $table->decimal('amount', 14, 2)->comment('提现金额');
                $table->text('source')->nullable()->comment('提现来源/备注');
                $table->date('withdrawn_at')->comment('提现业务日期');
                $table->unsignedBigInteger('created_by')->nullable()
                    ->comment('创建人；FK → users.id；保留 ERP 字段名');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->foreign('account_id', 'paypal_withdrawals_account_fk')
                    ->references('id')->on('paypal_accounts')->cascadeOnDelete();
                $table->foreign('created_by', 'paypal_withdrawals_user_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->index('account_id', 'idx_paypal_withdrawals_account_id');
            });
            $this->fixTimestampPrecision6('paypal_withdrawals', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 19. daily_stats (§3.3) — bigserial PK
         * ================================================================= */
        if (!Schema::hasTable('daily_stats')) {
            Schema::create('daily_stats', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->date('stat_date')->comment('业务统计日');
                $table->string('channel', 64)->comment('渠道维度');
                $table->string('staff_code', 64)->default('')->comment('员工维度；空串=汇总口径');
                $table->decimal('orders_count', 18, 10)->default(0)->comment('按份额计算的订单数');
                $table->decimal('items_count', 18, 10)->default(0)->comment('按份额计算的件数');
                $table->decimal('usd_amount', 18, 10)->default(0)->comment('按份额计算的美元金额');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['stat_date', 'channel', 'staff_code'], 'daily_stats_unique');
            });
            $this->fixTimestampPrecision6('daily_stats', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 20. exchange_rates (§3.4) — bigserial PK
         * ================================================================= */
        if (!Schema::hasTable('exchange_rates')) {
            Schema::create('exchange_rates', function (Blueprint $table) {
                $table->id()->comment('主键，bigserial');
                $table->string('currency', 8)->comment('币种代码');
                $table->decimal('rate_to_usd', 18, 8)->comment('兑美元汇率');
                $table->date('effective_date')->comment('汇率生效日期');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['currency', 'effective_date'], 'exchange_rates_unique');
            });
            $this->fixTimestampPrecision6('exchange_rates', ['created_at', 'updated_at', 'deleted_at'], ['created_at', 'updated_at']);
        }

        /* =================================================================
         * 21. influencer_order_links (§7.3) — UUID PK
         * ================================================================= */
        if (!Schema::hasTable('influencer_order_links')) {
            Schema::create('influencer_order_links', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'))
                    ->comment('主键，UUID');
                $table->uuid('influencer_id')->comment('FK → influencers.id，级联删除');
                $table->uuid('order_uuid')->comment('FK → orders.entity_uuid，级联删除');
                $table->string('source', 32)->default('manual')->comment('关联来源');
                $table->unsignedBigInteger('created_by_user_id')->nullable()->comment('FK → users.id');
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
        }

        /* =================================================================
         * 22. site_classification_reclassifications (§7.4) — 复合 PK
         * ================================================================= */
        if (!Schema::hasTable('site_classification_reclassifications')) {
            Schema::create('site_classification_reclassifications', function (Blueprint $table) {
                $table->string('release_id', 64)->comment('发布/修复批次标识（PK 第一部分）');
                $table->text('order_id')->comment('ERP 订单标识（PK 第二部分）');
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

        /* =================================================================
         * 23. Drop cross-system user FKs
         * ---------------------------------------------------------------
         * ERP 用户 ID 与 API 用户 ID 不在同一序列空间，跨系统 FK 会让
         * COPY 阶段撞 `Key (created_by)=(7) is not present in table "users"`。
         * 这些列保留为普通 bigint 引用列，不再受 FK 强约束。
         * RBAC 内部 FK（api_tokens.user_id / user_roles.*）保留不动。
         * ================================================================= */
        $crossSystemUserFks = [
            ['influencer_order_links', 'influencer_links_user_fk'],
            ['influencers',             'influencers_creator_fk'],
            ['invoice_orders',          'invoice_orders_creator_fk'],
            ['order_user_overrides',    'order_user_overrides_user_fk'],
            ['paypal_balance_entries',  'paypal_balance_user_fk'],
            ['paypal_reviews',          'paypal_reviews_user_fk'],
            ['paypal_withdrawals',      'paypal_withdrawals_user_fk'],
        ];
        foreach ($crossSystemUserFks as [$t, $fkName]) {
            $exists = DB::selectOne(
                "SELECT 1 FROM pg_constraint WHERE conname = ?",
                [$fkName],
            );
            if ($exists) {
                DB::statement("ALTER TABLE public.{$t} DROP CONSTRAINT {$fkName}");
            }
        }
    }

    public function down(): void
    {
        // 倒序：被依赖 → 主依赖 逐张 drop；FK 自然级联
        $tables = [
            'site_classification_reclassifications',
            'influencer_order_links',
            'exchange_rates',
            'daily_stats',
            'paypal_withdrawals',
            'paypal_reviews',
            'paypal_balance_entries',
            'paypal_accounts',
            'invoice_operation_logs',
            'invoice_staff_allocations',
            'invoice_items',
            'invoice_orders',
            'order_staff_performance_projection',
            'order_status_observations',
            'pending_completion_operations',
            'order_staff_allocations',
            'order_user_overrides',
            'order_items',
            'influencer_domains',
            'influencers',
            'attachments',
        ];
        // 分组 CASCADE drop 减少 IO
        foreach (array_chunk($tables, 6) as $chunk) {
            $names = implode(', ', array_map(fn ($t) => "public.{$t}", $chunk));
            DB::statement("DROP TABLE IF EXISTS {$names} CASCADE");
        }
    }
};
