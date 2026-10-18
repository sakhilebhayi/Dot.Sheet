<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SheetStructureService;
use App\Services\SpreadsheetImportExportService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Excel and CSV export. The Excel download used to answer 500 on every PHP version: the file
 * was written through the "local" disk, which Laravel 11+ roots at storage/app/private, and then
 * looked for under storage/app. The export loops also started at row 1 and column 1 while the
 * editor counts from 0, so the first row and first column never made it into a file.
 *
 * The Excel tests build real .xlsx files with phpoffice/phpspreadsheet. CI runs them on PHP 8.4,
 * production's version.
 */
class SpreadsheetExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Spreadsheet $spreadsheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->spreadsheet = Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $this->user->id,
            'name' => 'Q1 Budget / Draft',
        ]);
        $this->tab = $this->spreadsheet->defaultSheetTab();
    }

    private function cell(int $row, int $col, ?string $raw, ?string $computed = null, ?string $formula = null, ?SheetTab $tab = null): void
    {
        Cell::create([
            'spreadsheet_id' => $this->spreadsheet->id,
            'sheet_tab_id' => ($tab ?? $this->tab)->id,
            'row_index' => $row,
            'col_index' => $col,
            'raw_value' => $raw,
            'computed_value' => $computed,
            'formula' => $formula,
            'updated_at' => now(),
        ]);
    }

    /**
     * Download the Excel export through the route and open the file it returns.
     */
    private function downloadedWorksheet(?SheetTab $tab = null): Worksheet
    {
        $query = $tab ? ['sheet_tab_id' => $tab->id] : [];

        $response = $this->actingAs($this->user)->get(route('spreadsheets.export.excel', ['spreadsheet' => $this->spreadsheet] + $query));

        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));

        $copy = tempnam(sys_get_temp_dir(), 'dotsheet-export').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $copy);

        return IOFactory::load($copy)->getActiveSheet();
    }

    public function test_excel_export_downloads_a_real_xlsx_file(): void
    {
        $this->cell(0, 0, 'Name');
        $this->cell(0, 1, 'Qty');
        $this->cell(1, 0, 'Widget');
        $this->cell(1, 1, '3');

        $sheet = $this->downloadedWorksheet();

        $this->assertSame('Name', $sheet->getCell('A1')->getValue());
        $this->assertSame('Qty', $sheet->getCell('B1')->getValue());
        $this->assertSame('Widget', $sheet->getCell('A2')->getValue());
        $this->assertSame(3, $sheet->getCell('B2')->getValue());
    }

    public function test_download_file_is_removed_from_the_server_after_it_is_sent(): void
    {
        $this->cell(0, 0, 'x');

        $response = $this->actingAs($this->user)->get(route('spreadsheets.export.excel', $this->spreadsheet));
        $response->assertOk();
        $this->assertCount(1, Storage::disk('local')->files('exports'));

        // Sending the response is what deletes the file.
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();

        $this->assertSame([], Storage::disk('local')->files('exports'));
    }

    public function test_excel_export_of_an_empty_sheet_is_a_valid_empty_file(): void
    {
        $sheet = $this->downloadedWorksheet();

        $this->assertNull($sheet->getCell('A1')->getValue());
    }

    public function test_excel_export_keeps_gaps_blank_rows_and_the_first_row_and_column(): void
    {
        $this->cell(0, 0, 'top-left');
        $this->cell(3, 2, 'deep');

        $sheet = $this->downloadedWorksheet();

        $this->assertSame('top-left', $sheet->getCell('A1')->getValue());
        $this->assertNull($sheet->getCell('A2')->getValue());
        $this->assertNull($sheet->getCell('B4')->getValue());
        $this->assertSame('deep', $sheet->getCell('C4')->getValue());
        $this->assertSame(4, $sheet->getHighestDataRow());
    }

    public function test_excel_export_writes_formula_results_not_formulas_and_numbers_as_numbers(): void
    {
        $this->cell(0, 0, '7');
        $this->cell(1, 0, '3');
        $this->cell(2, 0, '', '10', '=SUM(A1:A2)');
        $this->cell(3, 0, '1.50');
        $this->cell(4, 0, '007');
        $this->cell(5, 0, '-4');
        $this->cell(6, 0, '+27 82 123 4567');
        $this->cell(7, 0, '12345678901234567890');
        $this->cell(8, 0, '', '#REF!', '=#REF!');

        $sheet = $this->downloadedWorksheet();

        $this->assertSame(7, $sheet->getCell('A1')->getValue());
        $this->assertSame(10, $sheet->getCell('A3')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A3')->getDataType());
        $this->assertEquals(1.5, $sheet->getCell('A4')->getValue());
        $this->assertSame('007', $sheet->getCell('A5')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A5')->getDataType());
        $this->assertSame(-4, $sheet->getCell('A6')->getValue());
        $this->assertSame('+27 82 123 4567', $sheet->getCell('A7')->getValue());
        $this->assertSame('12345678901234567890', $sheet->getCell('A8')->getValue());
        $this->assertSame('#REF!', $sheet->getCell('A9')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A9')->getDataType());
    }

    public function test_excel_export_keeps_special_characters_and_never_creates_formulas_from_text(): void
    {
        $values = [
            'He said "hi", then left',
            "line one\nline two",
            'Zoë — Åsa 日本語 😀',
            '<script>alert(1)</script> & co',
            '=HYPERLINK("http://evil.example","click")',
            '@SUM(1+1)',
            "'quoted",
        ];
        foreach ($values as $row => $value) {
            $this->cell($row, 0, $value);
        }

        $sheet = $this->downloadedWorksheet();

        foreach ($values as $row => $value) {
            $cell = $sheet->getCell('A'.($row + 1));
            $this->assertSame($value, $cell->getValue(), "row {$row}");
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), "row {$row}");
        }
    }

    public function test_excel_export_only_contains_the_requested_tab(): void
    {
        $second = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Second', 'sort_order' => 1]);
        $this->cell(0, 0, 'first');
        $this->cell(0, 0, 'second', null, null, $second);

        $this->assertSame('first', $this->downloadedWorksheet()->getCell('A1')->getValue());
        $this->assertSame('second', $this->downloadedWorksheet($second)->getCell('A1')->getValue());
    }

    public function test_a_tab_id_from_another_spreadsheet_is_ignored(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'name' => 'Other']);
        $this->cell(0, 0, 'mine');
        Cell::create(['spreadsheet_id' => $other->id, 'sheet_tab_id' => $other->defaultSheetTab()->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => 'not mine', 'updated_at' => now()]);

        $this->assertSame('mine', $this->downloadedWorksheet($other->defaultSheetTab())->getCell('A1')->getValue());
    }

    public function test_excel_export_needs_a_signed_in_user_with_access(): void
    {
        $this->cell(0, 0, 'secret');

        $this->get(route('spreadsheets.export.excel', $this->spreadsheet))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('spreadsheets.export.excel', $this->spreadsheet))
            ->assertForbidden();
    }

    public function test_a_sheet_larger_than_the_limit_gets_a_plain_message_not_a_500(): void
    {
        config(['sheet.export_excel_max_cells' => 3]);
        foreach (range(0, 3) as $col) {
            $this->cell(0, $col, "c{$col}");
        }

        $this->actingAs($this->user)
            ->get(route('spreadsheets.export.excel', $this->spreadsheet))
            ->assertStatus(413)
            ->assertSee('Export it as CSV instead');

        $this->assertSame([], Storage::disk('local')->files('exports'));
    }

    public function test_a_sheet_at_the_limit_still_exports(): void
    {
        config(['sheet.export_excel_max_cells' => 4]);
        foreach (range(0, 3) as $col) {
            $this->cell(0, $col, "c{$col}");
        }

        $this->assertSame('c3', $this->downloadedWorksheet()->getCell('D1')->getValue());
    }

    public function test_a_large_sheet_exports_within_the_default_limit(): void
    {
        $rows = [];
        for ($r = 0; $r < 1500; $r++) {
            for ($c = 0; $c < 6; $c++) {
                $rows[] = ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $r, 'col_index' => $c, 'raw_value' => $c % 2 ? "text {$r}-{$c}" : (string) ($r * $c), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Cell::insert($chunk);
        }
        $this->assertLessThan(config('sheet.export_excel_max_cells'), count($rows));

        $sheet = $this->downloadedWorksheet();

        $this->assertSame(1500, $sheet->getHighestDataRow());
        $this->assertSame('text 1499-5', $sheet->getCell('F1500')->getValue());
    }

    public function test_csv_export_includes_the_first_row_and_column_and_quotes_properly(): void
    {
        $this->cell(0, 0, 'a');
        $this->cell(0, 1, 'He said "hi", then left');
        $this->cell(1, 1, "two\nlines");
        $this->cell(2, 0, '', '10', '=SUM(A1:A2)');

        $csv = app(SpreadsheetImportExportService::class)->exportCsv($this->spreadsheet);

        $this->assertSame([
            ['a', 'He said "hi", then left'],
            ['', "two\nlines"],
            ['10', ''],
        ], array_map('str_getcsv', $this->csvRows($csv)));
    }

    /**
     * @return list<string>
     */
    private function csvRows(string $csv): array
    {
        // One CSV record per entry, even when a quoted field holds a line break.
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $records = [];
        while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, $record, ',', '"', '');
            rewind($out);
            $records[] = rtrim(stream_get_contents($out), "\n");
        }

        return $records;
    }

    public function test_csv_export_stops_cells_being_run_as_formulas_in_excel(): void
    {
        $this->cell(0, 0, '=1+1');
        $this->cell(1, 0, '@SUM(A1)');
        $this->cell(2, 0, "+cmd|' /C calc'!A0");
        $this->cell(3, 0, '-2+3+cmd|x');
        $this->cell(4, 0, '+27 82 123 4567');
        $this->cell(5, 0, '-12.5');
        $this->cell(6, 0, 'plain');

        $rows = array_map(fn ($line) => str_getcsv($line)[0], $this->csvRows(app(SpreadsheetImportExportService::class)->exportCsv($this->spreadsheet)));

        $this->assertSame(["'=1+1", "'@SUM(A1)", "'+cmd|' /C calc'!A0", "'-2+3+cmd|x", '+27 82 123 4567', '-12.5', 'plain'], $rows);
    }

    public function test_csv_export_of_an_empty_sheet_is_empty(): void
    {
        $this->assertSame('', app(SpreadsheetImportExportService::class)->exportCsv($this->spreadsheet));
    }

    public function test_csv_route_downloads_with_a_safe_file_name(): void
    {
        $this->cell(0, 0, 'x');

        $this->actingAs($this->user)
            ->get(route('spreadsheets.export.csv', $this->spreadsheet))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="q1-budget-draft_'.now()->format('Ymd').'.csv"');
    }

    public function test_csv_import_puts_the_first_row_and_column_at_zero_and_reads_crlf_and_quotes(): void
    {
        $csv = "Name,Note\r\n\"Smith, J\",\"says \"\"hi\"\"\"\r\nline,\"two\r\nlines\"\r\n";

        $result = app(SpreadsheetImportExportService::class)->importCsv($this->spreadsheet, $csv);

        $this->assertTrue($result['success']);
        $cells = Cell::where('sheet_tab_id', $this->tab->id)->get()->mapWithKeys(fn ($c) => ["{$c->row_index}_{$c->col_index}" => $c->raw_value]);
        $this->assertSame('Name', $cells['0_0']);
        $this->assertSame('Note', $cells['0_1']);
        $this->assertSame('Smith, J', $cells['1_0']);
        $this->assertSame('says "hi"', $cells['1_1']);
        $this->assertSame("two\r\nlines", $cells['2_1']);
    }

    public function test_csv_round_trip_keeps_every_cell_in_place(): void
    {
        $this->cell(0, 0, 'Name');
        $this->cell(0, 2, 'Total, with comma');
        $this->cell(2, 1, "multi\nline");
        $service = app(SpreadsheetImportExportService::class);
        $csv = $service->exportCsv($this->spreadsheet);

        $copy = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'name' => 'Copy']);
        $service->importCsv($copy, $csv);

        $this->assertSame(
            ['0_0' => 'Name', '0_2' => 'Total, with comma', '2_1' => "multi\nline"],
            Cell::where('spreadsheet_id', $copy->id)->get()->mapWithKeys(fn ($c) => ["{$c->row_index}_{$c->col_index}" => $c->raw_value])->all()
        );
    }

    public function test_xlsx_round_trip_through_export_and_import_keeps_every_cell_in_place(): void
    {
        $this->cell(0, 0, 'Name');
        $this->cell(0, 1, 'Qty');
        $this->cell(1, 0, 'Widget');
        $this->cell(1, 1, '3');
        $service = app(SpreadsheetImportExportService::class);

        $path = $service->exportExcel($this->spreadsheet);
        $copy = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'name' => 'Copy']);
        $result = $service->importFile($copy, new UploadedFile($path, 'data.xlsx', null, null, true));
        @unlink($path);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertSame(
            ['0_0' => 'Name', '0_1' => 'Qty', '1_0' => 'Widget', '1_1' => '3'],
            Cell::where('spreadsheet_id', $copy->id)->get()->mapWithKeys(fn ($c) => ["{$c->row_index}_{$c->col_index}" => $c->raw_value])->all()
        );
    }

    public function test_a_single_far_away_cell_is_capped_by_the_grid_it_would_make(): void
    {
        // One stored cell, but the writer would have to lay out 100 columns x 60,001 rows.
        $this->cell(60000, 99, 'far');

        $this->actingAs($this->user)
            ->get(route('spreadsheets.export.excel', $this->spreadsheet))
            ->assertStatus(413)
            ->assertSee('Export it as CSV instead')
            ->assertSee('grid of 6,000,100 cells');

        $this->assertSame([], Storage::disk('local')->files('exports'));
        // CSV has no such limit.
        $this->actingAs($this->user)->get(route('spreadsheets.export.csv', $this->spreadsheet))->assertOk();
    }

    public function test_csv_round_trip_is_lossless_for_text_that_looks_like_a_formula(): void
    {
        $values = ['-5%', '@john', '- bullet', '=text', "'=quoted", "'+27 82", '+27 82 123 4567', "'plain", '-12.5', "\tindented", '+cmd|x', "it's"];
        foreach ($values as $row => $value) {
            $this->cell($row, 0, $value);
        }
        $service = app(SpreadsheetImportExportService::class);
        $copy = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'name' => 'Copy']);

        $service->importCsv($copy, $service->exportCsv($this->spreadsheet));

        $imported = Cell::where('spreadsheet_id', $copy->id)->orderBy('row_index')->get();
        $this->assertSame($values, $imported->pluck('raw_value')->all());
        // ... and none of them became a formula.
        $this->assertSame([], $imported->whereNotNull('formula')->pluck('formula')->all());
    }

    public function test_csv_import_still_reads_a_real_formula_as_a_formula(): void
    {
        app(SpreadsheetImportExportService::class)->importCsv($this->spreadsheet, "1,2,=A1+B1\n");

        $this->assertSame('=A1+B1', Cell::where('col_index', 2)->value('formula'));
    }

    public function test_xlsx_import_keeps_text_that_starts_with_an_equals_sign_as_text(): void
    {
        $this->cell(0, 0, '=hello');
        $this->cell(1, 0, '=SUM(1,2) is text here');
        $this->cell(2, 0, '7');
        $service = app(SpreadsheetImportExportService::class);

        $path = $service->exportExcel($this->spreadsheet);
        $copy = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'name' => 'Copy']);
        $service->importFile($copy, new UploadedFile($path, 'data.xlsx', null, null, true));
        @unlink($path);

        $cells = Cell::where('spreadsheet_id', $copy->id)->orderBy('row_index')->get();
        $this->assertSame(['=hello', '=SUM(1,2) is text here', '7'], $cells->pluck('raw_value')->all());
        $this->assertSame([null, null, null], $cells->pluck('formula')->all());
        $this->assertSame('=hello', $cells[0]->computed_value);
    }

    public function test_a_text_cell_starting_with_equals_is_not_rewritten_as_a_formula_on_insert(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->cell(0, 0, '=A2');
        $this->cell(1, 0, '5');

        app(SheetStructureService::class)->insertRow($this->spreadsheet->id, $this->tab->id, 0);

        $this->assertSame('=A2', Cell::where('row_index', 1)->where('col_index', 0)->value('raw_value'));
    }

    public function test_the_prune_command_removes_only_old_export_files(): void
    {
        Storage::disk('local')->put('exports/old.xlsx', 'x');
        Storage::disk('local')->put('exports/fresh.xlsx', 'x');
        touch(Storage::disk('local')->path('exports/old.xlsx'), now()->subHours(2)->getTimestamp());

        $this->artisan('sheet:prune-exports')->expectsOutputToContain('Deleted 1 old export file')->assertSuccessful();

        $this->assertSame(['exports/fresh.xlsx'], Storage::disk('local')->files('exports'));
    }

    public function test_the_prune_command_is_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'sheet:prune-exports'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }
}
