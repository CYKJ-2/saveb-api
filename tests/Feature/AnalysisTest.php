<?php

namespace Tests\Feature;

use App\Dao\AnalysisDao;
use App\Models\AnalysisImport;
use App\Models\AnalysisProcurementRow;
use App\Models\ApiToken;
use App\Models\InvoiceOrder;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AnalysisClassificationService;
use App\Services\AnalysisImportService;
use App\Services\AnalysisOrderLinkService;
use App\Services\AnalysisWorkbookParser;
use App\Services\AnalysisWorkbookReader;
use Database\Seeders\AnalysisMenuSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class AnalysisTest extends TestCase
{
    private string $schema;

    private string $files;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RBAC_TEST_POSTGRES') !== '1') {
            $this->markTestSkipped('Use phpunit-analysis.xml with PostgreSQL.');
        }
        $this->schema = 'rbac_test_' . bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA ' . $this->schema);
        DB::statement('SET search_path TO ' . $this->schema);
        (require database_path('migrations/2026_09_04_180000_create_rbac.php'))->up();
        (require database_path('migrations/2026_09_10_200000_create_analysis_tables.php'))->up();
        $this->files = storage_path('framework/testing/' . $this->schema);
        File::makeDirectory($this->files, 0775, true);
        config(['filesystems.disks.local.root' => $this->files . '/private']);
        app('filesystem')->forgetDisk('local');
        $this->assertSame($this->schema, DB::selectOne('SELECT current_schema() AS name')->name);
        $role = Role::where('code', 'super_admin')->firstOrFail();
        Permission::create(['code' => 'business', 'name' => 'Business', 'type' => 'menu', 'level' => 1]);
        (new AnalysisMenuSeeder())->run();
        $user = User::create(['username' => 'analysis-test', 'display_name' => 'Test', 'password_hash' => Hash::make('Test-123'), 'active' => 1, 'role_id' => $role->id]);
        $this->withHeader('Authorization', 'Bearer ' . ApiToken::issue($user->id)['plain']);
        // All order-source reads are stubbed: tests must never read collector or real sales rows.
        $dao = Mockery::mock(AnalysisDao::class)->makePartial();
        $dao->shouldReceive('sourceCustomerFields')->andReturn(collect());
        $dao->shouldReceive('ordinaryOrders')->andReturn(collect());
        $dao->shouldReceive('invoiceOrders')->andReturn(collect());
        $this->app->instance(AnalysisDao::class, $dao);
    }

    protected function tearDown(): void
    {
        if (isset($this->schema) && preg_match('/^rbac_test_[a-f0-9]{16}$/D', $this->schema)) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            if (isset($this->files) && $this->files === storage_path('framework/testing/' . $this->schema)) {
                File::deleteDirectory($this->files);
            }
        }
        parent::tearDown();
    }

    public function test_xlsx_reader_preserves_all_sheets_hidden_cells_and_formula_cache(): void
    {
        $file = $this->xlsx(['包包' => [['A' => '品牌', 'B' => '品牌英文'], ['A' => '香奈儿', 'AF' => '隐藏列文本']], '2026.09' => [['A' => '实际成交价格'], ['A' => '330.125']]]);
        $zip = new ZipArchive();
        $zip->open($file);
        $xml = $zip->getFromName('xl/worksheets/sheet2.xml');
        $xml = str_replace('<c r="A2" t="inlineStr"><is><t xml:space="preserve">330.125</t></is></c>', '<c r="A2"><f>660.25/2</f><v>330.125</v></c>', $xml);
        $zip->addFromString('xl/worksheets/sheet2.xml', $xml);
        $zip->close();
        $sheets = iterator_to_array(app(AnalysisWorkbookReader::class)->sheets($file));
        $this->assertSame(['包包', '2026.09'], array_column($sheets, 'name'));
        $this->assertSame('隐藏列文本', $sheets[0]['rows'][1]['cells']['AF']);
        $this->assertSame('330.125', $sheets[1]['rows'][1]['cells']['A']);
        $this->assertSame('660.25/2', $sheets[1]['rows'][1]['formulas']['A']);
    }

    public function test_price_parser_preserves_missing_values_and_rejects_ambiguous_prices(): void
    {
        $parser = app(AnalysisWorkbookParser::class);
        $this->assertSame('330.13', $parser->decimal('330.125', 2));
        $this->assertSame('-30.30', $parser->decimal('-30.3', 2));
        $this->assertSame('1250.00', $parser->decimal('CNY 1,250', 2));
        foreach (['', '330+50', '330/件', '=100+2', '10,50', '1e3'] as $raw) {
            $this->assertNull($parser->decimal($raw, 2), $raw);
        }
        $this->assertNull($parser->date('09.01'));
        $this->assertSame('2026-08-28', $parser->date('2026. 08. 28'));
        $sheet = iterator_to_array(app(AnalysisWorkbookReader::class)->sheets($this->procurementFile(2)))[0];
        $unknown = $parser->procurementRows($sheet, null, 'unknown');
        $this->assertSame('330.13', $unknown[0]['actual_price']);
        $this->assertNull($unknown[0]['analysis_amount']);
        $unit = $parser->procurementRows($sheet, 'CNY', 'unit');
        $this->assertNull($unit[0]['analysis_amount']);
        $this->assertContains('amount_basis_incomplete', $unit[0]['issues']);
        $sheet['rows'][1]['cells']['H'] = 'USD 330';
        $conflict = $parser->procurementRows($sheet, 'CNY', 'row_total')[0];
        $this->assertSame('currency_conflict', $conflict['price_status']);
        $this->assertNull($conflict['analysis_amount']);
    }

    public function test_supplier_rules_disambiguate_categories_without_guessing_brand_codes(): void
    {
        $this->importSupplier();
        $classifier = app(AnalysisClassificationService::class);
        $classifier->load(app(AnalysisDao::class)->activeRules());
        $ids = DB::table('analysis_categories')->pluck('id', 'code')->all();
        $matched = $classifier->classify(['supplier_raw' => '双利', 'brand_raw' => 'CA', 'product_description' => '手链19'], $ids);
        $this->assertSame($ids['jewelry'], $matched['category_id']);
        $ambiguous = $classifier->classify(['brand_raw' => 'CH', 'product_description' => '项链'], $ids);
        $this->assertNull($ambiguous['brand_id']);
        $this->assertCount(2, $ambiguous['classification_evidence']['brand_candidates']);
        $conflict = $classifier->classify(['supplier_raw' => '双利', 'brand_raw' => 'CA', 'product_description' => '短袖'], $ids);
        $this->assertNull($conflict['category_id']);
        $this->assertSame('conflict', $conflict['classification_status']);
        $this->assertSame($ids['watches'], $classifier->classify(['supplier_raw' => '钟表商', 'brand_raw' => 'CA', 'product_description' => '腕表'], $ids)['category_id']);
    }

    public function test_imports_are_atomic_and_idempotent_and_keep_repeated_product_rows(): void
    {
        $this->importSupplier();
        $file = $this->procurementFile(22);
        $service = app(AnalysisImportService::class);
        $first = $service->import($file, 'orders.xlsx', 'procurement', 'CNY', 'row_total', null);
        $second = $service->import($file, 'orders.xlsx', 'procurement', 'CNY', 'row_total', null);
        $this->assertSame($first['id'], $second['id']);
        $this->assertTrue($second['reused']);
        $this->assertSame(22, AnalysisProcurementRow::count());
        $this->assertSame(22, $first['summary']['category_matched']);
        $bad = $this->xlsx(['2026.09' => [['A' => 'unsupported header']]]);
        try {
            $service->import($bad, 'bad.xlsx', 'procurement', 'CNY', 'row_total', null);
            $this->fail('Malformed import must fail');
        } catch (ValidationException) {
            $this->assertSame($first['id'], AnalysisImport::where('source_type', 'procurement')->where('is_active', true)->value('id'));
        }
        $replacement = $service->import($this->procurementFile(2), 'new.xlsx', 'procurement', 'USD', 'row_total', null);
        $this->assertNotSame($first['id'], $replacement['id']);
        $this->assertSame(24, AnalysisProcurementRow::count());
        $this->getJson('/api/workbench/analysis/rows')->assertOk()->assertJsonPath('data.total', 2);
    }

    public function test_paginated_rows_export_and_aggregates_share_filters_and_language(): void
    {
        $this->importSupplier();
        app(AnalysisImportService::class)->import($this->procurementFile(22), 'orders.xlsx', 'procurement', 'CNY', 'row_total', null);
        $this->getJson('/api/workbench/analysis/rows')->assertOk()->assertJsonCount(20, 'data.list')->assertJsonPath('data.total', 22)->assertJsonPath('data.per_page', 20);
        $this->getJson('/api/workbench/analysis/rows?page=2')->assertOk()->assertJsonCount(2, 'data.list');
        $report = $this->getJson('/api/workbench/analysis/report?startDate=2026-08-01&endDate=2026-08-31&grain=day')->assertOk()->json('data');
        $this->assertSame('7262.86', $report['totals'][0]['amount']);
        $this->assertCount(31, $report['trend']['periods']);
        $this->assertSame('2026-08-28', $report['trend']['points'][0]['period']);
        $this->assertSame(22, $report['summary']['rows']);
        $this->getJson('/api/workbench/analysis/report?startDate=2026-09-01')->assertOk()->assertJsonPath('data.summary.rows', 0);
        foreach (['zh-CN', 'en-US'] as $locale) {
            $options = $this->getJson('/api/workbench/analysis/options?locale=' . $locale)->assertOk()->json('data');
            $rows = $this->getJson('/api/workbench/analysis/rows?locale=' . $locale)->assertOk()->json('data.list');
            $csv = $this->get('/api/workbench/analysis/export?page=2&locale=' . $locale)->assertOk()->streamedContent();
            $lines = preg_split('/\r?\n/', trim(substr($csv, 3)));
            $this->assertCount(23, $lines);
            $this->assertSame(array_column($options['columns'], 'label'), str_getcsv($lines[0], ',', '"', ''));
            $this->assertSame(array_map('strval', array_values($rows[0]['display'])), str_getcsv($lines[1], ',', '"', ''));
        }
    }

    public function test_cancelled_missing_prices_and_conflicting_currency_are_not_silent_zeroes(): void
    {
        $file = $this->procurementFile(4, ['330+20', '', '0', '$20']);
        $sheets = iterator_to_array(app(AnalysisWorkbookReader::class)->sheets($file));
        $sheets[0]['rows'][1]['cells']['V'] = '客户取消';
        $file = $this->xlsx(['2026.09' => array_column($sheets[0]['rows'], 'cells')]);
        app(AnalysisImportService::class)->import($file, 'prices.xlsx', 'procurement', 'CNY', 'row_total', null);
        $data = $this->getJson('/api/workbench/analysis/report')->assertOk()->json('data');
        $this->assertSame(3, $data['summary']['rows']);
        $this->assertSame(1, $data['summary']['amount_rows']);
        $this->assertSame('0.00', $data['totals'][0]['amount']);
        $this->getJson('/api/workbench/analysis/rows?include_cancelled=1')->assertOk()->assertJsonPath('data.total', 4);
        $this->getJson('/api/workbench/analysis/rows?per_page=100000')->assertUnprocessable();
    }

    public function test_order_links_require_corroboration_and_use_history_before_selected_month(): void
    {
        $dao = Mockery::mock(AnalysisDao::class);
        $dao->shouldReceive('sourceCustomerFields')->andReturn(collect([
            (object) ['order_id' => 'global1', 'email' => 'client@example.test', 'country' => 'USA'],
            (object) ['order_id' => 'global2', 'email' => 'CLIENT@example.test', 'country' => 'US'],
        ]));
        $dao->shouldReceive('ordinaryOrders')->andReturn(collect([
            (new Order())->forceFill(['id' => 1, 'order_id' => 'global1', 'client_order_id' => '55', 'customer_name' => 'Alex', 'order_time' => '2026-07-01T04:00:00Z', 'order_status' => 'completed']),
            (new Order())->forceFill(['id' => 2, 'order_id' => 'global2', 'client_order_id' => '55', 'customer_name' => 'Alex', 'order_time' => '2026-08-28T04:00:00Z', 'order_status' => 'completed']),
        ]));
        $dao->shouldReceive('invoiceOrders')->andReturn(collect([
            new InvoiceOrder(['id' => 3, 'order_number' => '56', 'customer_full_name' => 'Alex', 'customer_email' => 'client@example.test', 'country' => 'Unknown', 'order_date' => '2026-09-01', 'invoice_status' => 'Paid']),
        ]));
        $linker = new AnalysisOrderLinkService($dao);
        $linker->load();
        $row = ['record_type' => 'ordinary', 'order_number' => '55', 'customer_name' => 'Alex'];
        $this->assertSame('ambiguous', $linker->link($row)['order_match_status']);
        $linked = $linker->link($row + ['customer_order_date' => '2026-08-28']);
        $this->assertSame(2, $linked['linked_order_id']);
        $this->assertSame('returning', $linked['customer_type']);
        $this->assertSame('US', $linked['country']);
        $this->assertSame('first', $linker->link($row + ['customer_order_date' => '2026-07-01'])['customer_type']);
        $invoice = ['record_type' => 'invoice', 'order_number' => '56'];
        $this->assertSame('needs_evidence', $linker->link($invoice)['order_match_status']);
        $this->assertSame('returning', $linker->link($invoice + ['customer_name' => 'Alex'])['customer_type']);
        $this->assertNull($linker->link($invoice + ['customer_name' => 'Alex'])['country']);
    }

    public function test_permissions_keep_import_and_export_separate_from_reading(): void
    {
        $role = Role::create(['code' => 'analysis_reader', 'name' => 'Reader', 'status' => 1]);
        $role->permissions()->sync([Permission::where('code', 'business.analysis.list')->value('id')]);
        $user = User::create(['username' => 'reader', 'display_name' => 'Reader', 'password_hash' => Hash::make('Test-123'), 'active' => 1, 'role_id' => $role->id]);
        $this->withHeader('Authorization', 'Bearer ' . ApiToken::issue($user->id)['plain']);
        $this->getJson('/api/workbench/analysis/report')->assertOk();
        $this->get('/api/workbench/analysis/export')->assertForbidden();
        $this->postJson('/api/workbench/analysis/imports', [])->assertForbidden();
        $this->withHeader('Authorization', '');
        $this->getJson('/api/workbench/analysis/rows')->assertUnauthorized();
    }

    private function importSupplier(): void
    {
        $file = $this->xlsx([
            '珠宝' => [
                ['A' => '品牌', 'B' => '品牌', 'C' => '代号', 'D' => '供应商'],
                ['A' => '卡地亚', 'B' => 'CARTIER', 'C' => 'CA', 'D' => '双利'],
                ['A' => '香奈儿', 'B' => 'CHANEL', 'C' => 'CH'],
                ['A' => '克罗心', 'B' => 'CHROME HEARTS', 'C' => 'CH'],
            ],
            '手表' => [['A' => '品牌'], ['A' => '卡地亚', 'B' => 'CARTIER', 'C' => 'CA', 'D' => '钟表商']],
        ]);
        app(AnalysisImportService::class)->import($file, 'suppliers.xlsx', 'suppliers', null, 'unknown', null);
    }

    private function procurementFile(int $count, array $prices = []): string
    {
        $rows = [['A' => '顾客下单日期', 'B' => '发起采购日期', 'C' => '下单形式/单号', 'D' => '品牌', 'E' => '货号', 'F' => '供应商', 'G' => '供应商定价', 'H' => '实际成交价格', 'J' => '客户名', 'V' => '最终不予以发货则必填原因']];
        for ($index = 0; $index < $count; $index++) {
            $rows[] = ['A' => '2026.08.28', 'B' => '2026.09.01', 'C' => 'WS-20529', 'D' => 'CA', 'E' => '手链19', 'F' => '双利', 'G' => '500', 'H' => $prices[$index] ?? '330.125', 'J' => 'Fixture Customer'];
        }

        return $this->xlsx(['2026.09' => $rows]);
    }

    private function xlsx(array $sheets): string
    {
        $path = $this->files . '/' . bin2hex(random_bytes(8)) . '.xlsx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $book = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $index = 0;
        foreach ($sheets as $name => $rows) {
            $index++;
            $book .= '<sheet name="' . $escape($name) . '" sheetId="' . $index . '" r:id="rId' . $index . '"/>';
            $rels .= '<Relationship Id="rId' . $index . '" Target="worksheets/sheet' . $index . '.xml"/>';
            $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="17" max="32" hidden="1"/></cols><sheetData>';
            foreach ($rows as $offset => $cells) {
                $number = $offset + 1;
                $xml .= '<row r="' . $number . '">';
                foreach ($cells as $column => $value) {
                    $xml .= '<c r="' . $column . $number . '" t="inlineStr"><is><t xml:space="preserve">' . $escape((string) $value) . '</t></is></c>';
                }
                $xml .= '</row>';
            }
            $zip->addFromString('xl/worksheets/sheet' . $index . '.xml', $xml . '</sheetData></worksheet>');
        }
        $zip->addFromString('xl/workbook.xml', $book . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels . '</Relationships>');
        $zip->close();

        return $path;
    }
}
