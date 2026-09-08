<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('procurement_tasks')) {
            Schema::create('procurement_tasks', function (Blueprint $t) {
                $t->id();
                $t->string('legacy_id')->nullable()->unique();
                $t->string('order_id')->nullable()->index();
                $t->string('purchase_status')->default('pending_purchase');
                $t->string('supplier')->nullable();
                $t->decimal('cost', 14, 2)->nullable();
                $t->date('eta')->nullable();
                $t->string('tracking_no')->nullable();
                $t->text('notes')->nullable();
                $t->jsonb('raw')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->integer('version')->default(1);
                $t->timestampsTz();
                $t->softDeletesTz();
            });
        }
        if (!Schema::hasTable('procurement_removed_orders')) {
            Schema::create('procurement_removed_orders', function (Blueprint $t) {
                $t->id();
                $t->string('legacy_id')->unique();
                $t->string('order_id')->nullable();
                $t->string('paypal_order_id')->nullable();
                $t->jsonb('raw')->nullable();
                $t->unsignedBigInteger('removed_by')->nullable();
                $t->timestampTz('removed_at')->useCurrent();
                $t->timestampsTz();
                $t->softDeletesTz();
            });
        }
        if (!Schema::hasTable('warehouse_records')) {
            Schema::create('warehouse_records', function (Blueprint $t) {
                $t->id();
                $t->foreignId('procurement_task_id')->unique()->constrained('procurement_tasks');
                $t->string('fulfillment_status')->default('pending_inspection');
                $t->jsonb('items')->nullable();
                $t->jsonb('history')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->integer('version')->default(1);
                $t->timestampsTz();
                $t->softDeletesTz();
            });
        }
        if (!Schema::hasTable('business_operation_logs')) {
            Schema::create('business_operation_logs', function (Blueprint $t) {
                $t->id();
                $t->string('module')->index();
                $t->string('entity_id')->index();
                $t->string('action');
                $t->unsignedBigInteger('actor_user_id');
                $t->jsonb('before')->nullable();
                $t->jsonb('after')->nullable();
                $t->timestampsTz();
            });
        }
        if (!Schema::hasTable('online_spreadsheets')) {
            Schema::create('online_spreadsheets', function (Blueprint $t) {
                $t->id();
                $t->string('source_key')->unique();
                $t->string('department');
                $t->string('provider');
                $t->string('title_zh');
                $t->string('title_en');
                $t->text('description_zh')->nullable();
                $t->text('description_en')->nullable();
                $t->text('url');
                $t->integer('sort')->default(0);
                $t->boolean('active')->default(true);
                $t->timestampsTz();
                $t->softDeletesTz();
            });
        }
        if (!Schema::hasColumn('attachments', 'owner_user_id')) {
            Schema::table('attachments', fn (Blueprint $t) => $t->unsignedBigInteger('owner_user_id')->nullable());
        }
        if (!Schema::hasColumn('paypal_accounts', 'version')) {
            Schema::table('paypal_accounts', fn (Blueprint $t) => $t->integer('version')->default(1));
        }
    }

    public function down(): void
    { /* Retain business records on rollback. */
    }
};
