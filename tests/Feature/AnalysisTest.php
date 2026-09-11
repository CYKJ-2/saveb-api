<?php

namespace Tests\Feature;

use App\Models\AnalysisImport;
use App\Models\AnalysisProcurementRow;
use App\Models\ApiToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AnalysisImportService;
use App\Services\AnalysisWorkbookParser;
use App\Services\AnalysisWorkbookReader;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\AnalysisMenuSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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
        Carbon::setTestNow('2026-09-10 12:00:00');
        CarbonImmutable::setTestNow('2026-09-10 12:00:00');
        $this->schema = 'rbac_test_' . bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA ' . $this->schema);
        DB::statement('SET search_path TO ' . $this->schema);
        foreach (['2026_09_04_180000_create_rbac', '2026_09_10_200000_create_analysis_tables', '2026_09_10_210000_version_analysis_by_procurement_month'] as $migration) {
            (require database_path('migrations/' . $migration . '.php'))->up();
        }
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
        // This schema deliberately has no order or Invoice tables: analysis must not query them.
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        if (isset($this->schema) && preg_match('/^rbac_test_[a-f0-9]{16}$/D', $this->schema)) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            if (isset($this->files) && $this->files === storage_path('framework/testing/' . $this->schema)) {
                File::deleteDirectory($this->files);
            }
        }
        parent::tearDown();
    }

    public function test_reader_supports_prefixed_xml_and_skips_unselected_months(): void
    {
        $file = $this->xlsx(['2026.08' => [['A' => 'ignored']], '2026.09' => [['A' => 'brand'], ['AF' => 'Hidden field', 'A' => '330.125']]]);
        $zip = new ZipArchive();
        $zip->open($file);
        $xml = $zip->getFromName('xl/worksheets/sheet2.xml');
        $xml = preg_replace('/<(\/?)(worksheet|cols|col|sheetData|row|c|is|t)(?=[\s>\/])/', '<$1:x$2', $xml);
        $xml = str_replace(':x', 's:', $xml);
        $xml = str_replace('xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"', 'xmlns:s="http://schemas.openxmlformats.org/spreadsheetml/2006/main"', $xml);
        $xml = str_replace('<s:c r="A2" t="inlineStr"><s:is><s:t xml:space="preserve">330.125</s:t></s:is></s:c>', '<s:c r="A2"><s:f>660.25/2</s:f><s:v>330.125</s:v></s:c>', $xml);
        $zip->addFromString('xl/worksheets/sheet2.xml', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', 'malformed ignored sheet');
        $zip->close();
        $sheets = iterator_to_array(app(AnalysisWorkbookReader::class)->sheets($file, static fn ($name) => $name === '2026.09'));
        $this->assertCount(1, $sheets);
        $this->assertSame('Hidden field', $sheets[0]['rows'][1]['cells']['AF']);
        $this->assertSame('330.125', $sheets[0]['rows'][1]['cells']['A']);
        $this->assertSame('660.25/2', $sheets[0]['rows'][1]['formulas']['A']);
    }

    public function test_prices_and_missing_fields_are_preserved_without_quantity_inference(): void
    {
        $parser = app(AnalysisWorkbookParser::class);
        $this->assertSame('330.13', $parser->decimal('330.125', 2));
        $this->assertSame('-30.30', $parser->decimal('-30.3', 2));
        $this->assertSame('1250.00', $parser->decimal('CNY 1,250', 2));
        foreach (['', '550-20', '#REF!', '330/件', '=100+2', '10,50', '1e3'] as $raw) {
            $this->assertNull($parser->decimal($raw, 2), $raw);
        }
        $this->assertNull($parser->date('09.01'));
        $this->assertSame('2026-08-28', $parser->date('2026. 08. 28'));
        $file = $this->xlsx(['2026.09' => $this->purchaseRows([
            ['H' => '#REF!'], ['H' => '100', 'J' => '未采购'], ['H' => 'USD 100'],
            ['H' => '20.125', 'E' => '3件套'], ['B' => '', 'H' => '10'], ['H' => '0'],
        ])]);
        $result = $this->import($file);
        $this->assertSame('30.13', $result['summary']['amount']);
        $this->assertSame(3, $result['summary']['eligible_rows']);
        $this->assertSame(6, AnalysisProcurementRow::where('is_current', true)->count());
        $this->assertSame(0, AnalysisProcurementRow::whereNotNull('quantity')->count());
        $this->assertNull(AnalysisProcurementRow::where('row_number', 2)->value('actual_price'));
        $this->assertSame(1, $result['summary']['missing_date']);
    }

    public function test_pair_mapping_preserves_pending_brands_and_supplier_named_brand(): void
    {
        $header = ['A' => '品牌', 'B' => '品牌', 'C' => '代号', 'D' => '供应商优选1', 'E' => '确认状态'];
        $supplier = $this->xlsx([
            '包包' => [$header, ['A' => '香奈儿', 'B' => 'CHANEL', 'C' => 'CH', 'D' => '包商']],
            '珠宝' => [$header, ['A' => '克罗心', 'B' => 'CHROME HEARTS', 'C' => 'CH', 'D' => '品牌'],
                ['A' => '宝格丽', 'B' => 'BVLGARI', 'C' => 'BV', 'D' => '珠宝商']],
            '衣服' => [$header, ['A' => '待确认', 'C' => 'BV', 'D' => '衣商', 'E' => '品牌待确认']],
        ]);
        $this->import($supplier, 'suppliers');
        $this->import($this->xlsx(['2026.09' => $this->purchaseRows([
            ['D' => 'CH', 'F' => '包商'], ['D' => 'CH', 'F' => '品牌'],
            ['D' => 'BV', 'F' => '衣商'], ['D' => '???', 'F' => '未知商'],
        ])]));
        $rows = AnalysisProcurementRow::where('is_current', true)->orderBy('row_number')->get();
        $this->assertSame('CHANEL', $rows[0]->brand_name_en);
        $this->assertSame('CHROME HEARTS', $rows[1]->brand_name_en);
        $this->assertSame('jewelry', $rows[1]->category_code);
        $this->assertSame('clothing', $rows[2]->category_code);
        $this->assertSame('pending', $rows[2]->brand_match_status);
        $this->assertSame('待分类', $rows[2]->brand_name);
        $this->assertSame($rows[2]->brand_id, $rows[3]->brand_id);
        $this->assertNotNull($rows[3]->supplier_id);
        $this->assertSame('BV', $rows[2]->brand_raw);
    }

    public function test_unconfirmed_supplier_relationships_do_not_override_confirmed_mappings(): void
    {
        $header = ['A' => '品牌', 'B' => '品牌', 'C' => '代号', 'D' => '供应商优选1', 'E' => '确认状态'];
        $this->import($this->xlsx([
            '珠宝' => [$header, ['A' => '宝格丽', 'B' => 'BVLGARI', 'C' => 'BV', 'D' => '珠宝商']],
            '包包' => [$header, ['A' => '待确认', 'C' => 'BV', 'D' => '珠宝商', 'E' => '品牌/供应关系待确认']],
            '衣服' => [$header, ['A' => '待确认', 'C' => 'NEW', 'D' => '新商家', 'E' => '品牌/供应关系待确认'],
                ['A' => '待确认', 'C' => 'CONFIRMED', 'D' => '衣商', 'E' => '品牌待确认']],
        ]), 'suppliers');
        $this->import($this->xlsx(['2026.09' => $this->purchaseRows([
            ['D' => 'BV', 'F' => '珠宝商'], ['D' => 'BV', 'F' => '未登记供应商'],
            ['D' => 'NEW', 'F' => '新商家'], ['D' => 'CONFIRMED', 'F' => '衣商'],
        ])]));
        $rows = AnalysisProcurementRow::where('is_current', true)->orderBy('row_number')->get();
        $this->assertSame('jewelry', $rows[0]->category_code);
        $this->assertSame('BVLGARI', $rows[0]->brand_name_en);
        $this->assertSame('BVLGARI', $rows[1]->brand_name_en);
        $this->assertSame('unclassified', $rows[2]->category_code);
        $this->assertSame('待分类', $rows[2]->brand_name);
        $this->assertSame('clothing', $rows[3]->category_code);
        $this->assertSame('pending', $rows[3]->brand_match_status);
    }

    public function test_daily_upload_replaces_only_current_month_and_preserves_snapshot_exports(): void
    {
        $first = $this->import($this->xlsx([
            '2026.08' => $this->purchaseRows([['B' => '2026-08-01', 'H' => '200']]),
            '2026.09' => $this->purchaseRows([['H' => '100'], ['H' => '100']]),
        ]), 'procurement', 'initialize');
        $export = app(\App\Dao\AnalysisDao::class)->exportRows([]);
        $augustIds = AnalysisProcurementRow::where('source_period', '2026-08')->pluck('id')->all();
        $newFile = $this->xlsx([
            '2026.08' => [['A' => 'Historical sheet intentionally invalid; it must not be read']],
            '2026.09' => $this->purchaseRows([['H' => '50']]),
        ]);
        $second = $this->import($newFile);
        $repeated = $this->import($newFile);
        $this->assertTrue($repeated['reused']);
        $this->assertSame($second['id'], $repeated['id']);
        $this->assertSame(2, AnalysisProcurementRow::where('is_current', true)->count());
        $this->assertSame($augustIds, AnalysisProcurementRow::where('is_current', true)->where('source_period', '2026-08')->pluck('id')->all());
        $this->assertEquals(250, AnalysisProcurementRow::where('is_current', true)->sum('analysis_amount'));
        $this->assertSame(['2026-08'], AnalysisImport::find($first['id'])->active_periods);
        $this->assertCount(3, $export->all());
        $this->assertCount(2, app(\App\Dao\AnalysisDao::class)->exportRows([])->all());
    }

    public function test_invalid_or_missing_month_upload_rolls_back_without_erasing_history(): void
    {
        $first = $this->import($this->xlsx(['2026.09' => $this->purchaseRows([['H' => '125']])]));
        $missingPriceHeader = $this->purchaseRows([['H' => '500']]);
        unset($missingPriceHeader[0]['H']);
        foreach ([
            $this->xlsx(['2026.09' => $missingPriceHeader]),
            $this->xlsx(['2026.08' => $this->purchaseRows([[]])]),
            $this->xlsx(['2026.09' => [['A' => 'wrong header']]]),
            $this->xlsx(['2026.09' => $this->purchaseRows([])]),
        ] as $file) {
            try {
                $this->import($file);
                $this->fail('Invalid import should fail.');
            } catch (ValidationException) {
                $this->assertSame(1, AnalysisImport::where('source_type', 'procurement')->count());
                $this->assertSame($first['id'], AnalysisProcurementRow::where('is_current', true)->value('import_id'));
            }
        }
        $this->expectException(ValidationException::class);
        $this->import($this->xlsx(['2026.08' => $this->purchaseRows([[]])]), 'procurement', 'initialize');
    }

    public function test_first_and_repeat_use_full_procurement_history_before_filtering(): void
    {
        $this->import($this->xlsx([
            '2026.08' => $this->purchaseRows([['B' => '2026-08-01', 'I' => '  Alex  Smith ', 'H' => '100']]),
            '2026.09' => $this->purchaseRows([
                ['I' => 'alex smith', 'H' => '200'], ['I' => 'Beth', 'H' => '30'], ['I' => ' BETH ', 'H' => '40'],
                ['I' => 'Beth', 'B' => '2026-09-02', 'H' => '50'], ['I' => '', 'H' => '60'],
                ['I' => 'Beth', 'B' => '2026-09-03', 'J' => '未采购', 'H' => '500'],
            ]),
        ]), 'procurement', 'initialize');
        $this->assertSame(
            ['first', 'returning', 'first', 'first', 'returning', 'unknown', 'unknown'],
            AnalysisProcurementRow::where('is_current', true)->orderBy('id')->pluck('customer_type')->all(),
        );
        $report = $this->getJson('/api/workbench/analysis/report?startDate=2026-09-01&endDate=2026-09-30')->assertOk()->json('data');
        $this->assertSame('380.00', $report['summary']['amount']);
        $groups = collect($report['distributions']['customer_type'])->keyBy('key');
        $this->assertSame('250.00', $groups['returning']['amount']);
        $customers = $this->getJson('/api/workbench/analysis/customers?startDate=2026-09-01')->assertOk()->json('data');
        $this->assertSame('2026-08-01', $customers['list'][0]['first_date']);
        $this->assertSame(2, $customers['total']);
        $this->assertNotEmpty($report['crosses']['brand_category']);
        $this->assertNotEmpty($report['crosses']['category_price']);
    }

    public function test_cross_shares_and_dimension_totals_use_the_same_filtered_amount(): void
    {
        $header = ['A' => '中文品牌', 'B' => '英文品牌', 'C' => '代号', 'D' => '供应商'];
        $this->import($this->xlsx([
            '包包' => [$header, ['A' => '香奈儿', 'B' => 'CHANEL', 'C' => 'CH', 'D' => '包商']],
            '衣服' => [$header, ['A' => '耐克', 'B' => 'NIKE', 'C' => 'NI', 'D' => '衣商']],
        ]), 'suppliers');
        $this->import($this->xlsx([
            '2026.08' => $this->purchaseRows([['B' => '2026-08-01', 'I' => 'Alex', 'H' => '1000']]),
            '2026.09' => $this->purchaseRows([
                ['D' => 'CH', 'F' => '包商', 'I' => 'Alex', 'H' => '100.10'],
                ['D' => 'NI', 'F' => '衣商', 'I' => 'Beth', 'H' => '200.20'],
                ['D' => '待确认', 'F' => '未知供应商', 'I' => '', 'H' => '99.70'],
                ['H' => '99999', 'J' => '未采购'], ['H' => '#REF!'],
            ]),
        ]), 'procurement', 'initialize');

        $query = '/api/workbench/analysis/report?startDate=2026-09-01&endDate=2026-09-30';
        $report = $this->getJson($query)->assertOk()->json('data');
        $this->assertSame('400.00', $report['summary']['amount']);
        $this->assertSame(3, $report['summary']['eligible_rows']);
        foreach (['category_price' => ['category', 'price_band'], 'customer_brand' => ['customer_type', 'brand']] as $cross => $dimensions) {
            $cells = collect($report['crosses'][$cross]);
            $this->assertSame('400.00', $cells->reduce(fn ($sum, $cell) => bcadd($sum, $cell['amount'], 2), '0.00'));
            foreach (['x', 'y'] as $axisIndex => $axis) {
                foreach ($report['distributions'][$dimensions[$axisIndex]] as $total) {
                    $group = $cells->where($axis, $total['key']);
                    $this->assertSame($total['amount'], $group->reduce(fn ($sum, $cell) => bcadd($sum, $cell['amount'], 2), '0.00'));
                    $this->assertSame($total['rows'], $group->sum('rows'));
                }
            }
        }
        $customers = collect($report['crosses']['customer_brand'])->keyBy('x');
        $this->assertSame('25.03', $customers['returning']['share']);
        $this->assertSame('50.05', $customers['first']['share']);
        $this->assertSame('24.93', $customers['unknown']['share']);
        $this->assertSame('待分类', $customers['unknown']['y_zh']);

        // A brand filter changes the denominator, but never resets the customer's first date.
        $filtered = $this->getJson($query . '&brand_id=' . $customers['returning']['y'])->assertOk()->json('data');
        $this->assertSame('100.10', $filtered['summary']['amount']);
        $this->assertSame('100.00', $filtered['crosses']['category_price'][0]['share']);
        $this->assertSame('100.00', $filtered['crosses']['customer_brand'][0]['share']);
        $this->assertSame('returning', $filtered['crosses']['customer_brand'][0]['x']);
    }

    public function test_cross_shares_handle_zero_totals_and_negative_amounts(): void
    {
        $this->import($this->xlsx(['2026.09' => $this->purchaseRows([
            ['I' => 'Credit', 'H' => '100'], ['I' => 'Credit', 'H' => '-100'],
            ['I' => 'Zero', 'H' => '0'], ['I' => 'Sale', 'H' => '100'],
        ])]));
        $query = '/api/workbench/analysis/report?startDate=2026-09-01&endDate=2026-09-30';
        foreach (['Credit', 'Zero', 'No such customer'] as $keyword) {
            $report = $this->getJson($query . '&keyword=' . urlencode($keyword))->assertOk()->json('data');
            $this->assertSame('0.00', $report['summary']['amount']);
            foreach (['category_price', 'customer_brand'] as $cross) {
                foreach ($report['crosses'][$cross] as $cell) {
                    $this->assertSame('0.00', $cell['share']);
                }
            }
        }
        $report = $this->getJson($query)->assertOk()->json('data');
        $this->assertSame('100.00', $report['summary']['amount']);
        $bands = collect($report['crosses']['category_price'])->keyBy('y');
        $this->assertSame('-100.00', $bands['negative']['share']);
        $this->assertSame('200.00', $bands['100-299.99']['share']);
    }

    public function test_trend_expands_calendar_periods_without_expanding_other_statistics(): void
    {
        $this->import($this->xlsx([
            '2025.12' => $this->purchaseRows([['B' => '2025-12-31', 'H' => '500']]),
            '2026.07' => $this->purchaseRows([['B' => '2026-07-01', 'C' => 'PL-300', 'H' => '999']]),
            '2026.08' => $this->purchaseRows([['B' => '2026-08-31', 'H' => '200']]),
            '2026.09' => $this->purchaseRows([['B' => '2026-09-01', 'H' => '100']]),
        ]), 'procurement', 'initialize');

        $query = '/api/workbench/analysis/report?startDate=2026-09-01&endDate=2026-09-30&purchase_method=ws';
        $monthly = $this->getJson($query . '&grain=month')->assertOk()->json('data');
        $this->assertSame('100.00', $monthly['summary']['amount']);
        $this->assertSame(1, $monthly['summary']['eligible_rows']);
        $this->assertSame('100.00', $monthly['distributions']['purchase_method'][0]['amount']);
        $this->assertCount(12, $monthly['trend']['periods']);
        $this->assertSame('2026-01', $monthly['trend']['periods'][0]);
        $this->assertSame('2026-12', $monthly['trend']['periods'][11]);
        $this->assertSame(['2026-08', '2026-09'], array_column($monthly['trend']['points'], 'period'));
        $this->assertSame(['200.00', '100.00'], array_column($monthly['trend']['points'], 'amount'));

        $daily = $this->getJson($query . '&grain=day')->assertOk()->json('data');
        $this->assertCount(30, $daily['trend']['periods']);
        $this->assertSame('2026-09-01', $daily['trend']['periods'][0]);
        $this->assertSame('2026-09-30', $daily['trend']['periods'][29]);
        $this->assertSame(['2026-09-01'], array_column($daily['trend']['points'], 'period'));
        $this->assertSame($monthly['summary'], $daily['summary']);

        $partial = $this->getJson('/api/workbench/analysis/report?startDate=2026-09-02&endDate=2026-09-03&grain=day')->assertOk()->json('data');
        $this->assertSame('0.00', $partial['summary']['amount']);
        $this->assertCount(30, $partial['trend']['periods']);
        $this->assertSame('100.00', $partial['trend']['points'][0]['amount']);

        foreach ([['2024-02-10', 'day', 29, '2024-02-01', '2024-02-29'],
            ['2026-10-10', 'day', 31, '2026-10-01', '2026-10-31'],
            ['2027-01-01', 'month', 12, '2027-01', '2027-12']] as [$date, $grain, $count, $first, $last]) {
            $empty = $this->getJson('/api/workbench/analysis/report?' . http_build_query(['startDate' => $date, 'endDate' => $date, 'grain' => $grain]))->assertOk()->json('data');
            $this->assertSame('0.00', $empty['summary']['amount']);
            $this->assertCount($count, $empty['trend']['periods']);
            $this->assertSame($first, $empty['trend']['periods'][0]);
            $this->assertSame($last, $empty['trend']['periods'][$count - 1]);
            $this->assertSame([], $empty['trend']['points']);
        }
    }

    public function test_pagination_quality_bilingual_export_and_history_endpoint(): void
    {
        $overrides = array_fill(0, 23, ['H' => '10']);
        $overrides[] = ['H' => '#REF!', 'I' => '=1+1'];
        $this->import($this->xlsx(['2026.09' => $this->purchaseRows($overrides)]));
        $response = $this->getJson('/api/workbench/analysis/rows')->assertOk()->json('data');
        $this->assertCount(20, $response['list']);
        $this->assertSame(23, $response['total']);
        $this->assertCount(3, $this->getJson('/api/workbench/analysis/rows?page=2')->assertOk()->json('data.list'));
        $this->assertSame(1, $this->getJson('/api/workbench/analysis/rows?quality=missing')->assertOk()->json('data.total'));
        $this->assertSame(0, $this->getJson('/api/workbench/analysis/rows?scope=all&price_band=3000%2B')->assertOk()->json('data.total'));
        $this->getJson('/api/workbench/analysis/imports')->assertOk();
        $this->getJson('/api/workbench/analysis/options')->assertOk()->assertJsonPath('data.currency', 'CNY');
        $zh = $this->get('/api/workbench/analysis/export?locale=zh-CN&scope=all')->assertOk()->streamedContent();
        $en = $this->get('/api/workbench/analysis/export?locale=en-US&scope=all')->assertOk()->streamedContent();
        $this->assertStringContainsString('实际成交价格', $zh);
        $this->assertStringContainsString('Actual transaction price', $en);
        $this->assertStringContainsString("'=1+1", $en);
        $this->assertStringNotContainsString('Country', $en);
    }

    public function test_supplier_updates_reclassify_history_without_changing_source_or_amount(): void
    {
        $this->import($this->xlsx(['2026.08' => $this->purchaseRows([['B' => '2026-08-01', 'H' => '120', 'D' => 'CH', 'F' => '包商']])]), 'procurement', 'initialize');
        $before = AnalysisProcurementRow::where('is_current', true)->firstOrFail();
        $this->assertSame('待分类', $before->brand_name);
        $supplier = $this->xlsx(['包包' => [
            ['A' => '品牌', 'B' => '品牌', 'C' => '代号', 'D' => '供应商优选1'],
            ['A' => '香奈儿', 'B' => 'CHANEL', 'C' => 'CH', 'D' => '包商'],
        ]]);
        $batch = $this->import($supplier, 'suppliers');
        $after = $before->fresh();
        $this->assertSame('CHANEL', $after->brand_name_en);
        $this->assertSame('bags', $after->category_code);
        $this->assertSame($batch['id'], $after->mapping_import_id);
        $this->assertSame($before->raw, $after->raw);
        $this->assertSame($before->analysis_amount, $after->analysis_amount);
        $this->assertSame($before->import_id, $after->import_id);
        $this->assertTrue($this->import($supplier, 'suppliers')['reused']);
        $this->assertSame(1, AnalysisProcurementRow::where('is_current', true)->count());
    }

    public function test_permissions_separate_import_export_and_read(): void
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

    private function import(string $file, string $source = 'procurement', string $mode = 'current_month'): array
    {
        return app(AnalysisImportService::class)->import($file, basename($file), $source, 'CNY', 'row_total', null, $mode);
    }

    private function purchaseRows(array $overrides): array
    {
        $rows = [['A' => '顾客下单日期', 'B' => '发起采购日期', 'C' => '下单形式/单号',
            'D' => '品牌', 'E' => '货号', 'F' => '供应商', 'G' => '供应商定价', 'H' => '实际成交价格', 'I' => '客户名', 'J' => '是否采购']];
        foreach ($overrides as $override) {
            $rows[] = array_replace(['A' => '2026-08-28', 'B' => '2026-09-01', 'C' => 'WS-200',
                'D' => 'CA', 'E' => '手链19', 'F' => '双利', 'G' => '500', 'H' => '330.125', 'I' => 'Customer', 'J' => '已采购'], $override);
        }

        return $rows;
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
