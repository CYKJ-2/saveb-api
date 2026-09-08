<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 订单主表迁移。
 *
 * 对应 saveb-erp / saveb-source 项目 /api/order-search 接口的数据结构。
 *
 * 表：orders
 *  ────────────────────────────────────────────────────────────
 *   核心字段（与 order-search 接口展示内容一致）：
 *     - client_order_id  : 客户端订单号（前端表单 "Order ID" 输入框）
 *     - order_id         : 系统订单号（保留为历史兼容；saveb-erp 早期 schema 中
 *                          即以 order_id 作为客户订单号，后续追加 client_order_id
 *                          字段；本迁移保留双字段以保持向后兼容）
 *     - paypal_order_id  : PayPal 订单号（前端表单 "PayPal Order ID"）
 *     - order_time       : 下单时间
 *     - customer_name    : 顾客姓名（前端 "Customer Full Name"）
 *     - source_site      : 来源网站（前端 "Website"）
 *     - classification   : 订单归类，例如 official/top_influencer/mid_influencer/payment_link/invoice
 *     - influencer_name  : 关联达人
 *     - receiving_paypal : 收款 PayPal 账号（前端 "Receiving PayPal"）
 *     - amount_original  / currency / amount_usd : 原始金额 / 币种 / 美元金额
 *     - items_count      : 商品件数
 *     - product_name     : 商品名称
 *     - order_status     : 订单状态（前端 "Order Status" 下拉：Completed/Pending/...）
 *     - staff_code       : 主负责客服（前端 "Customer Service" 筛选）
 *     - raw              : 原始 JSON 负载，便于扩展
 *
 *   业务字段：
 *     - entity_uuid      : 外部稳定 UUID（用于跨系统对账）
 *     - visible_order_id : 业务可见订单号（自增序号，给前台展示）
 *     - version          : 乐观锁版本号
 *     - deleted_at       : 软删除时间戳
 *     - created_at / updated_at : 标准审计字段
 *
 * 索引策略：
 *   - 时间 / 状态 / 客服 / 分类 / 收款 PayPal / 客户订单号 / PayPal 订单号
 *     都加 btree 索引以支撑分页 + 筛选的常见查询
 *   - customer_name 启用 pg_trgm + GIN 索引以支持模糊匹配
 *
 * 设计原则：
 *   - 不在 schema 层做软强制约束（如 CHECK），业务约束交给 Service 层
 *   - 字段全部 NULLABLE 化（除明确必填），便于从外部数据源导入脏数据
 *   - 时间戳精度统一为 timestamptz(6) + CURRENT_TIMESTAMP 默认
 */
return new class () extends Migration {
    /**
     * 把指定表的时间戳列精度提升到 6，并按列名补默认值。
     *
     * @param  string            $table         表名
     * @param  array<int,string> $columns       时间列名列表
     * @param  array<int,string> $withDefaults  需要默认值的列
     */
    private function fixTimestampPrecision6(string $table, array $columns, array $withDefaults = []): void
    {
        $defaults = array_flip($withDefaults);
        foreach ($columns as $col) {
            $sql = "ALTER TABLE {$table} ALTER COLUMN {$col} TYPE timestamptz(6)";
            if (isset($defaults[$col])) {
                $sql .= ", ALTER COLUMN {$col} SET DEFAULT CURRENT_TIMESTAMP";
            }
            DB::statement($sql);
        }
    }

    public function up(): void
    {
        // pg_trgm 用于 customer_name 的 ILIKE 模糊匹配
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // pgcrypto 用于 gen_random_uuid() 默认值（PostgreSQL 13+ 内置）
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        // 业务可见订单号序列：单调递增，给前台 / 客服使用
        DB::statement("CREATE SEQUENCE IF NOT EXISTS saveb_visible_order_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE");

        Schema::create('orders', function (Blueprint $table) {
            $table->id()->comment('主键，自增序列');

            // 订单标识
            $table->string('order_id', 64)
                ->comment('系统订单号，全局唯一；保留为历史兼容字段');
            $table->string('client_order_id', 128)->nullable()
                ->comment('客户端订单号（对应前端"Order ID"筛选）；与 order_id 同源但允许后到');
            $table->string('paypal_order_id', 128)->nullable()
                ->comment('PayPal 订单号（对应前端"PayPal Order ID"筛选）');

            // 业务可见序号：给前台 / 客服展示的"业务订单号"
            $table->unsignedBigInteger('visible_order_id')
                ->comment('业务可见订单号，由 saveb_visible_order_id_seq 序列生成，给前台展示');

            // 跨系统对账 UUID
            $table->uuid('entity_uuid')
                ->comment('外部稳定 UUID，跨系统对账用')
                ->default(DB::raw('gen_random_uuid()'));

            // 时间
            $table->timestampTz('order_time')->nullable()
                ->comment('下单时间（业务时间，可能与 created_at 不同）');

            // 业务字段
            $table->string('customer_name', 255)->nullable()
                ->comment('顾客姓名（对应前端"Customer Full Name"筛选）');
            $table->string('source_site', 255)->nullable()
                ->comment('来源网站域名（对应前端"Website"筛选）');
            $table->string('classification', 64)->nullable()
                ->comment('订单归类：official/top_influencer/mid_influencer/payment_link/invoice/unmatched');
            $table->string('influencer_name', 255)->nullable()
                ->comment('关联达人名称');
            $table->string('receiving_paypal', 255)->nullable()
                ->comment('收款 PayPal 账号（对应前端"Receiving PayPal"筛选）');
            $table->decimal('amount_original', 14, 2)->nullable()
                ->comment('订单原始金额，按 currency 计价');
            $table->string('currency', 16)->nullable()
                ->comment('原始金额币种');
            $table->decimal('amount_usd', 14, 2)->nullable()
                ->comment('折算美元金额（业务统计 / 报表用）');
            $table->integer('items_count')->default(1)
                ->comment('商品件数');
            $table->string('product_name', 500)->nullable()
                ->comment('商品名称');
            $table->string('order_status', 64)->nullable()
                ->comment('订单状态（对应前端"Order Status"下拉：Completed/Pending/Reversed/Refunded/Failed/Expired）');
            $table->string('staff_code', 64)->nullable()
                ->comment('主负责客服（对应前端"Customer Service"筛选）');
            $table->jsonb('raw')->default(DB::raw("'{}'::jsonb"))
                ->comment('原始 JSON 负载，便于扩展与第三方对账');

            // 乐观锁
            $table->integer('version')->default(1)
                ->comment('乐观锁版本号，每次写入 +1');

            $table->timestampsTz();
            $table->softDeletesTz();

            // 唯一约束
            $table->unique('order_id', 'orders_order_id_unique');
            $table->unique('entity_uuid', 'orders_entity_uuid_unique');
            $table->unique('client_order_id', 'orders_client_order_id_unique');
            $table->unique('visible_order_id', 'orders_visible_order_id_unique');

            // 普通索引
            $table->index('order_time', 'idx_orders_time');
            $table->index('order_status', 'idx_orders_status');
            $table->index('staff_code', 'idx_orders_staff');
            $table->index('classification', 'idx_orders_classification');
            $table->index('paypal_order_id', 'idx_orders_paypal_order_id');
            $table->index('receiving_paypal', 'idx_orders_receiving_paypal');
            $table->index(['order_time', 'order_status'], 'idx_orders_time_status');
            $table->index(['order_time', 'staff_code'], 'idx_orders_time_staff');
        });

        // 业务可见订单号的默认值绑定到序列
        DB::statement("ALTER TABLE orders ALTER COLUMN visible_order_id SET DEFAULT nextval('saveb_visible_order_id_seq')");

        // customer_name 模糊匹配 GIN 索引
        DB::statement("CREATE INDEX idx_orders_customer_name_trgm ON orders USING gin (customer_name gin_trgm_ops)");

        // 提升时间戳精度到 (6)
        $this->fixTimestampPrecision6('orders', ['created_at', 'updated_at'], ['created_at', 'updated_at']);
        $this->fixTimestampPrecision6('orders', ['deleted_at', 'order_time'], []);
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
        DB::statement('DROP SEQUENCE IF EXISTS saveb_visible_order_id_seq');
    }
};
