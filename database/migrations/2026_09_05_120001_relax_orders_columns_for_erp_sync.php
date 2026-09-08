<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERP → API 同步 `orders` 表时暴露的 schema 不匹配点。
 *
 * 背景：
 *   ERP 端 orders 表把大部分字符串字段定义为 text（不限长），
 *   但 saveb-api 这边把 product_name 限定为 varchar(500)，
 *   并在 client_order_id 上加了 UNIQUE 约束。这两条约束都会让
 *   `copy-orders-data.ps1` 在 import 阶段撞墙：
 *     - product_name: ERP 11 条多商品订单字符串长度 514~1337
 *     - client_order_id: ERP 1864 个值在多条订单里复用
 *
 * 修复：
 *   1. product_name → TEXT，并加一个 ≤ 2000 的 CHECK 兜底
 *      （保留一个软上限，避免任何上游写出 MB 级的字符串）
 *   2. DROP CONSTRAINT orders_client_order_id_unique
 *      因为 client_order_id 在业务上并非全局唯一。
 *      真正的业务唯一键是 entity_uuid（已有 UNIQUE）。
 *
 * 幂等性：
 *   - 所有变更通过 information_schema / pg_constraint 判定后再执行
 *   - down() 还原最初的 schema（product_name 回 varchar(500)，
 *     重建 UNIQUE 但不强制去重 —— 仅恢复结构，业务数据是否允许
 *     重复由调用方负责）
 */
return new class () extends Migration {
    public function up(): void
    {
        /* ─── 1. product_name 放宽为 TEXT + ≤2000 软上限 ────────── */
        $colType = DB::selectOne(
            "SELECT data_type, character_maximum_length
             FROM information_schema.columns
             WHERE table_schema='public'
               AND table_name='orders'
               AND column_name='product_name'",
        );
        if ($colType && $colType->data_type !== 'text') {
            // 显式 USING 避免 ambiguous (Postgres 在某些版本上需要它)
            DB::statement('ALTER TABLE public.orders ALTER COLUMN product_name TYPE TEXT USING product_name::TEXT');
        }
        $hasCheck = DB::selectOne(
            "SELECT 1 FROM pg_constraint
             WHERE conrelid='public.orders'::regclass
               AND contype='c'
               AND conname='orders_product_name_length_check'",
        );
        if (!$hasCheck) {
            DB::statement(
                'ALTER TABLE public.orders
                 ADD CONSTRAINT orders_product_name_length_check
                 CHECK (product_name IS NULL OR LENGTH(product_name) <= 2000)',
            );
        }

        /* ─── 2. 移除 client_order_id 的 UNIQUE 约束 ───────────── */
        $clientUniq = DB::selectOne(
            "SELECT conname FROM pg_constraint
             WHERE conrelid='public.orders'::regclass
               AND contype='u'
               AND conname='orders_client_order_id_unique'",
        );
        if ($clientUniq) {
            DB::statement('ALTER TABLE public.orders DROP CONSTRAINT orders_client_order_id_unique');
        }
    }

    public function down(): void
    {
        /* ─── 1. 还原 product_name ──────────────────────────────── */
        DB::statement('ALTER TABLE public.orders DROP CONSTRAINT IF EXISTS orders_product_name_length_check');
        // 截断到 500 字符再回退类型，避免超长数据导致 down() 失败
        DB::statement(
            "UPDATE public.orders
                SET product_name = LEFT(product_name, 500)
              WHERE product_name IS NOT NULL
                AND LENGTH(product_name) > 500",
        );
        DB::statement('ALTER TABLE public.orders ALTER COLUMN product_name TYPE VARCHAR(500) USING product_name::VARCHAR(500)');

        /* ─── 2. 重建 UNIQUE（仅恢复结构；如存在重复行此步会失败） */
        $clientUniq = DB::selectOne(
            "SELECT conname FROM pg_constraint
             WHERE conrelid='public.orders'::regclass
               AND contype='u'
               AND conname='orders_client_order_id_unique'",
        );
        if (!$clientUniq) {
            // 复用 partial unique：仅当 client_order_id 非空时强制唯一
            // （保持 ERP 数据原貌 — 不去重 — 实际业务上仍允许重复）
            DB::statement(
                "CREATE UNIQUE INDEX orders_client_order_id_unique
                 ON public.orders (client_order_id)
                 WHERE client_order_id IS NOT NULL",
            );
        }
    }
};
