<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('analysis_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 30);
            $table->string('source_url', 500);
            $table->string('filename');
            $table->string('file_hash', 64);
            $table->string('signature', 64)->unique();
            $table->boolean('is_active')->default(false)->index();
            $table->string('currency', 3)->nullable();
            $table->string('price_basis', 20)->default('unknown');
            $table->jsonb('summary');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
            $table->index(['source_type', 'is_active']);
        });

        Schema::create('analysis_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name_zh', 100);
            $table->string('name_en', 100);
            $table->timestampsTz();
        });
        Schema::create('analysis_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('identity', 64)->unique();
            $table->string('name_zh', 200);
            $table->string('name_en', 200);
            $table->timestampsTz();
        });
        Schema::create('analysis_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('identity', 64)->unique();
            $table->string('name', 500);
            $table->timestampsTz();
        });
        Schema::create('analysis_supplier_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('analysis_imports');
            $table->foreignId('category_id')->constrained('analysis_categories');
            $table->foreignId('brand_id')->nullable()->constrained('analysis_brands');
            $table->foreignId('supplier_id')->nullable()->constrained('analysis_suppliers');
            $table->string('brand_code', 100)->nullable();
            $table->string('sheet_name', 100);
            $table->unsignedInteger('row_number');
            $table->unsignedSmallInteger('preference');
            $table->jsonb('raw');
            $table->timestampsTz();
            $table->index(['import_id', 'supplier_id', 'brand_id'], 'analysis_rules_lookup');
        });
        Schema::create('analysis_procurement_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('analysis_imports');
            $table->string('sheet_name', 100);
            $table->unsignedInteger('row_number');
            $table->string('row_hash', 64);
            $table->jsonb('raw');
            $table->date('customer_order_date')->nullable();
            $table->date('procurement_date')->nullable();
            $table->date('analysis_date')->nullable()->index();
            $table->string('date_basis', 30);
            $table->text('order_reference')->nullable();
            $table->string('order_number', 150)->nullable()->index();
            $table->string('record_type', 30);
            $table->text('brand_raw')->nullable();
            $table->text('product_description')->nullable();
            $table->text('supplier_raw')->nullable();
            $table->text('customer_name')->nullable();
            $table->text('price_raw')->nullable();
            $table->string('price_column', 100)->nullable();
            $table->decimal('actual_price', 18, 2)->nullable();
            $table->decimal('quantity', 14, 4)->nullable();
            $table->decimal('analysis_amount', 20, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('price_basis', 20);
            $table->string('price_status', 30);
            $table->text('purchase_status')->nullable();
            $table->boolean('is_cancelled')->default(false);
            $table->foreignId('category_id')->nullable()->constrained('analysis_categories');
            $table->foreignId('brand_id')->nullable()->constrained('analysis_brands');
            $table->foreignId('supplier_id')->nullable()->constrained('analysis_suppliers');
            $table->string('classification_status', 30)->default('unmatched')->index();
            $table->jsonb('classification_evidence');
            $table->string('linked_order_type', 20)->nullable();
            $table->unsignedBigInteger('linked_order_id')->nullable();
            $table->string('order_match_status', 30)->default('unmatched');
            $table->string('country', 100)->nullable();
            $table->string('customer_type', 20)->default('unknown');
            $table->jsonb('issues');
            $table->timestampsTz();
            $table->unique(['import_id', 'sheet_name', 'row_number'], 'analysis_rows_source_position');
            $table->index(['import_id', 'analysis_date'], 'analysis_rows_date');
            $table->index(['import_id', 'category_id'], 'analysis_rows_category');
            $table->index(['import_id', 'brand_id'], 'analysis_rows_brand');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_procurement_rows');
        Schema::dropIfExists('analysis_supplier_rules');
        Schema::dropIfExists('analysis_suppliers');
        Schema::dropIfExists('analysis_brands');
        Schema::dropIfExists('analysis_categories');
        Schema::dropIfExists('analysis_imports');
    }
};
