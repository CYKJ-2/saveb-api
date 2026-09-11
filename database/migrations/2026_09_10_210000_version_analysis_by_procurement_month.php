<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('analysis_brands', function (Blueprint $table): void {
            $table->jsonb('aliases')->default('[]');
            $table->jsonb('source_entries')->default('[]');
            $table->boolean('is_active')->default(true);
        });
        Schema::table('analysis_suppliers', function (Blueprint $table): void {
            $table->jsonb('mapping_rules')->default('[]');
            $table->unsignedBigInteger('rules_import_id')->nullable();
        });
        Schema::table('analysis_imports', function (Blueprint $table): void {
            $table->string('mode', 30)->default('initialize');
            $table->jsonb('periods')->default('[]');
            $table->jsonb('active_periods')->default('[]');
            $table->string('status', 20)->default('completed');
        });
        Schema::table('analysis_procurement_rows', function (Blueprint $table): void {
            $table->string('source_period', 7)->nullable();
            $table->boolean('is_current')->default(false);
            $table->boolean('is_eligible')->default(false);
            $table->string('purchase_method', 30)->default('unknown');
            $table->text('customer_key')->nullable();
            $table->date('customer_first_date')->nullable();
            $table->decimal('supplier_quote', 20, 2)->nullable();
            $table->string('brand_match_status', 30)->default('unmatched');
            $table->string('brand_name', 200)->nullable();
            $table->string('brand_name_en', 200)->nullable();
            $table->string('category_code', 40)->nullable();
            $table->string('category_name', 100)->nullable();
            $table->string('category_name_en', 100)->nullable();
            $table->string('supplier_name', 500)->nullable();
            $table->unsignedBigInteger('mapping_import_id')->nullable();
            $table->timestampTz('mapped_at')->nullable();
            $table->index(['source_period', 'is_current', 'import_id'], 'analysis_current_partition');
        });
        DB::statement("UPDATE analysis_procurement_rows r SET source_period = COALESCE(to_char(r.procurement_date, 'YYYY-MM'), replace(r.sheet_name, '.', '-')), is_current = i.is_active FROM analysis_imports i WHERE i.id = r.import_id");
        DB::statement('CREATE INDEX analysis_current_date ON analysis_procurement_rows (analysis_date, id) WHERE is_current');
        DB::statement('CREATE INDEX analysis_current_brand ON analysis_procurement_rows (brand_id, analysis_date) WHERE is_current');
        DB::statement('CREATE INDEX analysis_current_category ON analysis_procurement_rows (category_id, analysis_date) WHERE is_current');
        DB::statement('CREATE INDEX analysis_current_customer ON analysis_procurement_rows (customer_key, analysis_date) WHERE is_current AND is_eligible');
    }

    public function down(): void
    {
        foreach (['analysis_current_date', 'analysis_current_brand', 'analysis_current_category', 'analysis_current_customer'] as $index) {
            DB::statement('DROP INDEX IF EXISTS ' . $index);
        }
        Schema::table('analysis_procurement_rows', function (Blueprint $table): void {
            $table->dropIndex('analysis_current_partition');
            $table->dropColumn(['source_period', 'is_current', 'is_eligible', 'purchase_method', 'customer_key', 'customer_first_date', 'supplier_quote', 'brand_match_status', 'brand_name', 'brand_name_en', 'category_code', 'category_name', 'category_name_en', 'supplier_name', 'mapping_import_id', 'mapped_at']);
        });
        Schema::table('analysis_imports', fn (Blueprint $table) => $table->dropColumn(['mode', 'periods', 'active_periods', 'status']));
        Schema::table('analysis_suppliers', fn (Blueprint $table) => $table->dropColumn(['mapping_rules', 'rules_import_id']));
        Schema::table('analysis_brands', fn (Blueprint $table) => $table->dropColumn(['aliases', 'source_entries', 'is_active']));
    }
};
