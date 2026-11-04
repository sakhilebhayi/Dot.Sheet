<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\Comment;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellDisplay;
use App\Services\CellWriter;
use App\Services\TemplateCatalogue;
use App\Services\WorkbookBuilder;
use App\Services\WorkbookImporter;
use App\Services\WorkbookSheets;
use App\Services\WorkbookWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Book;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkbookExportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    private SheetTab $other;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($this->owner);
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Year end']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->tab->update(['name' => 'Sales', 'color' => 'teal']);
        $this->other = app(WorkbookSheets::class)->add($this->sheet, 'Cash flow');
        $this->other->update(['hidden' => true]);

        $this->base("/tabs/{$this->tab->id}/sheet");
        $edits = [
            ['row' => 0, 'col' => 0, 'value' => 'Item', 'x' => ['bold' => true]],
            ['row' => 1, 'col' => 0, 'value' => '=1+1'],
            ['row' => 2, 'col' => 0, 'value' => 'R1,5'],
            ['row' => 1, 'col' => 1, 'value' => '1234.5', 'x' => ['number_format' => 'currency', 'currency' => 'USD', 'decimals' => 1]],
            ['row' => 2, 'col' => 1, 'value' => '0.25', 'x' => ['number_format' => 'percent', 'decimals' => 0, 'bg_color' => '#FBF0BF', 'font_color' => '#8F4A0E', 'align' => 'center', 'italic' => true]],
            ['row' => 3, 'col' => 1, 'value' => '46330', 'x' => ['number_format' => 'date', 'date_style' => 'long']],
            ['row' => 5, 'col' => 0, 'value' => 'Across two'],
        ];
        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/cells", ['version' => 0, 'edits' => $edits])->assertOk();
        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->other->id}/cells", ['version' => 0, 'edits' => [['row' => 0, 'col' => 0, 'value' => '=Sales!A2*2']]])->assertOk();

        Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 2)->where('col_index', 0)->update(['raw_value' => '=HYPERLINK("evil")', 'formula' => null]);
        SheetTab::updateLayout($this->tab->id, fn (array $layout) => [...$layout,
            'col_widths' => [0 => 215], 'row_heights' => [0 => 40], 'hidden_rows' => [8], 'hidden_cols' => [6],
            'merges' => [['r1' => 5, 'c1' => 0, 'r2' => 5, 'c2' => 1]], 'frozen_rows' => 1, 'frozen_cols' => 1,
            'validation_rules' => [['id' => 'a', 'row' => 1, 'col' => 3, 'row2' => 4, 'col2' => 3, 'type' => 'list', 'items' => 'One, Two'], ['id' => 'b', 'row' => 1, 'col' => 4, 'row2' => 4, 'col2' => 4, 'type' => 'number', 'min' => 1, 'max' => 9]],
            'conditional_formatting_rules' => [['id' => 'c', 'target' => 'column', 'row' => 1, 'col' => 1, 'operator' => '>', 'value' => '100', 'color' => '#F7E0DA']],
        ]);
        NamedRange::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'name' => 'Takings', 'start_row' => 1, 'start_col' => 1, 'end_row' => 3, 'end_col' => 1]);
        Comment::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'user_id' => $this->owner->id, 'row_index' => 0, 'col_index' => 0, 'content' => 'A remark']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function base(string $path): void
    {
        $this->getJson("/spreadsheets/{$this->sheet->id}{$path}")->assertOk();
    }

    private function written(string $format = 'xlsx'): Book
    {
        $path = app(WorkbookWriter::class)->write($this->sheet->fresh(), $format);
        $named = "{$path}.{$format}";
        rename($path, $named);
        $this->files[] = $named;

        return IOFactory::createReader($format === 'ods' ? 'Ods' : 'Xlsx')->load($named);
    }

    public function test_every_sheet_goes_out_with_its_formulas(): void
    {
        $book = $this->written();

        $this->assertSame(['Sales', 'Cash flow'], $book->getSheetNames());
        $this->assertSame('=1+1', $book->getSheet(0)->getCell('A2')->getValue());
        $this->assertSame('=Sales!A2*2', $book->getSheet(1)->getCell('A1')->getValue());
        $this->assertSame('hidden', $book->getSheet(1)->getSheetState());
        $this->assertSame(0, $book->getActiveSheetIndex());
    }

    public function test_text_that_looks_like_a_formula_goes_out_as_text(): void
    {
        $cell = $this->written()->getSheet(0)->getCell('A3');

        $this->assertSame('s', $cell->getDataType());
        $this->assertSame('=HYPERLINK("evil")', $cell->getValue());
    }

    public function test_formats_go_out_as_excel_codes(): void
    {
        $sheet = $this->written()->getSheet(0);

        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('"$" #,##0.0', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame('0%', $sheet->getStyle('B3')->getNumberFormat()->getFormatCode());
        $this->assertSame('FBF0BF', $sheet->getStyle('B3')->getFill()->getStartColor()->getRGB());
        $this->assertSame('8F4A0E', $sheet->getStyle('B3')->getFont()->getColor()->getRGB());
        $this->assertSame('center', $sheet->getStyle('B3')->getAlignment()->getHorizontal());
        $this->assertTrue($sheet->getStyle('B3')->getFont()->getItalic());
        $this->assertSame('d mmm yyyy', $sheet->getStyle('B4')->getNumberFormat()->getFormatCode());
    }

    public function test_the_shape_of_each_sheet_goes_out(): void
    {
        $sheet = $this->written()->getSheet(0);

        $this->assertEqualsWithDelta(30, $sheet->getColumnDimension('A')->getWidth(), 0.5);
        $this->assertEqualsWithDelta(30, $sheet->getRowDimension(1)->getRowHeight(), 0.5);
        $this->assertFalse($sheet->getRowDimension(9)->getVisible());
        $this->assertFalse($sheet->getColumnDimension('G')->getVisible());
        $this->assertArrayHasKey('A6:B6', $sheet->getMergeCells());
        $this->assertSame('B2', $sheet->getFreezePane());
    }

    public function test_rules_comments_names_and_colours_go_out(): void
    {
        $book = $this->written();
        $sheet = $book->getSheet(0);

        $this->assertSame('"One,Two"', $sheet->getCell('D2')->getDataValidation()->getFormula1());
        $this->assertSame('9', $sheet->getCell('E3')->getDataValidation()->getFormula2());
        $rules = array_merge(...array_values($sheet->getConditionalStylesCollection()));
        $this->assertSame('100', $rules[0]->getConditions()[0]);
        $this->assertSame('A remark', $sheet->getComment('A1')->getText()->getPlainText());
        $this->assertNotNull($book->getNamedRange('Takings'));
        $this->assertSame('0E8AA0', $sheet->getTabColor()->getRGB());
    }

    public function test_a_workbook_comes_back_the_same_after_going_out_and_in(): void
    {
        $path = app(WorkbookWriter::class)->write($this->sheet->fresh(), 'xlsx');
        $this->files[] = $path;

        $back = app(WorkbookImporter::class)->import($path, 'xlsx', 'Back', $this->owner, null);
        $sales = $back->sheetTabs()->where('name', 'Sales')->sole();

        $this->assertSame(['Sales', 'Cash flow'], $back->sheetTabs()->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame('=1+1', Cell::where('sheet_tab_id', $sales->id)->where('row_index', 1)->where('col_index', 0)->value('formula'));
        $this->assertSame(['number_format' => 'currency', 'currency' => 'USD', 'decimals' => 1], Cell::where('sheet_tab_id', $sales->id)->where('row_index', 1)->where('col_index', 1)->value('formatting'));
        $this->assertSame('teal', $sales->color);
        $this->assertSame([], $back->settings['import_report']['left']);
    }

    public function test_a_built_template_goes_out_whole(): void
    {
        $this->sheet = app(WorkbookBuilder::class)->build(app(TemplateCatalogue::class)->find('expense-tracker'), 'T', $this->owner, null);

        $book = $this->written();

        $this->assertSame(['Expenses', 'Summary'], $book->getSheetNames());
        $this->assertStringStartsWith('=SUMIFS(', $book->getSheet(1)->getCell('B5')->getValue());
    }

    public function test_it_downloads_as_excel_and_ods(): void
    {
        foreach (['xlsx', 'ods'] as $format) {
            $response = $this->get(route('spreadsheets.export.workbook', [$this->sheet, $format]));
            $response->assertOk();
            $this->assertStringContainsString('year-end_', (string) $response->headers->get('content-disposition'));
            $this->assertStringContainsString(".{$format}", (string) $response->headers->get('content-disposition'));
        }
    }

    public function test_ods_keeps_formulas_and_sheets(): void
    {
        $book = $this->written('ods');

        $this->assertSame(['Sales', 'Cash flow'], $book->getSheetNames());
        $this->assertSame('=1+1', $book->getSheet(0)->getCell('A2')->getValue());
    }

    public function test_only_known_formats_download(): void
    {
        $this->get("/spreadsheets/{$this->sheet->id}/export/workbook/docx")->assertNotFound();
    }

    public function test_a_workbook_too_large_is_refused_in_words(): void
    {
        config(['sheet.export_excel_max_cells' => 1]);

        $this->get(route('spreadsheets.export.workbook', [$this->sheet, 'xlsx']))->assertStatus(413);
    }

    public function test_only_people_who_can_open_it_can_download_it(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        foreach ([route('spreadsheets.export.workbook', [$this->sheet, 'xlsx']), route('spreadsheets.export.tsv', $this->sheet), route('spreadsheets.export.pdf', $this->sheet), route('spreadsheets.print-sheet', $this->sheet)] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_a_tsv_has_tabs_and_defuses_formulas(): void
    {
        $tsv = $this->get(route('spreadsheets.export.tsv', $this->sheet).'?sheet_tab_id='.$this->tab->id)->assertOk()->getContent();

        $this->assertStringContainsString("Item\t", (string) $tsv);
        $this->assertStringContainsString("'=HYPERLINK", (string) $tsv);
    }

    public function test_the_open_sheet_is_the_one_exported(): void
    {
        $csv = $this->get(route('spreadsheets.export.csv', $this->sheet).'?sheet_tab_id='.$this->other->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('Item', (string) $csv);
    }

    public function test_the_print_page_shows_what_the_cells_show(): void
    {
        $this->get(route('spreadsheets.print-sheet', $this->sheet).'?sheet_tab_id='.$this->tab->id)->assertOk()
            ->assertSee('Year end')->assertSee('Sales')->assertSee('$ 1 234.5')->assertSee('25%')->assertSee('4 Nov 2026')->assertSee('colspan="2"', false)
            ->assertSee('=HYPERLINK', false)->assertSee('font-weight:700', false);
    }

    public function test_the_print_page_leaves_out_what_is_hidden(): void
    {
        Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 3)->where('col_index', 1)->update(['raw_value' => 'keep']);
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [['row' => 8, 'col' => 0, 'value' => 'Hidden row'], ['row' => 0, 'col' => 6, 'value' => 'Hidden column']], $this->owner->id);

        $this->get(route('spreadsheets.print-sheet', $this->sheet).'?sheet_tab_id='.$this->tab->id)->assertDontSee('Hidden row')->assertDontSee('Hidden column');
    }

    public function test_what_people_typed_is_not_markup_on_the_print_page(): void
    {
        Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 0)->where('col_index', 0)->update(['raw_value' => '<script>alert(1)</script>', 'formatting' => json_encode(['bg_color' => 'red;background:url(x)'])]);

        $this->get(route('spreadsheets.print-sheet', $this->sheet).'?sheet_tab_id='.$this->tab->id)->assertDontSee('<script>alert', false)->assertDontSee('url(x)', false);
    }

    public function test_a_pdf_is_a_pdf(): void
    {
        $response = $this->get(route('spreadsheets.export.pdf', $this->sheet).'?sheet_tab_id='.$this->tab->id)->assertOk();

        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertStringContainsString('year-end_sales.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_a_sheet_too_large_for_paper_says_so(): void
    {
        config(['sheet.page_max_cells' => 1]);

        $this->get(route('spreadsheets.export.pdf', $this->sheet))->assertStatus(413);
        $this->get(route('spreadsheets.print-sheet', $this->sheet))->assertOk()->assertSee('too large to lay out for paper');
    }

    public function test_importing_excel_over_a_sheet_keeps_formulas_and_says_what_stayed_in_the_file(): void
    {
        $book = new Book;
        $book->getActiveSheet()->setCellValue('A1', 'Fresh')->setCellValue('B1', '=2+2');
        $book->getActiveSheet()->getStyle('A1')->getFont()->setBold(true);
        $book->createSheet()->setTitle('Second')->setCellValue('A1', 'x');
        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $this->files[] = $path;

        $answer = $this->post(route('spreadsheets.import', $this->sheet), ['file' => new UploadedFile($path, 'a.xlsx', null, null, true), 'sheet_tab_id' => $this->other->id])->assertOk()->json();

        $this->assertTrue($answer['success']);
        $this->assertSame(1, $answer['other_sheets']);
        $this->assertSame('=2+2', Cell::where('sheet_tab_id', $this->other->id)->where('row_index', 0)->where('col_index', 1)->value('formula'));
        $this->assertTrue(Cell::where('sheet_tab_id', $this->other->id)->where('row_index', 0)->where('col_index', 0)->value('formatting')['bold']);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>|null, 2: string}> */
    public static function shown(): array
    {
        return [
            'number' => ['1234.5', ['number_format' => 'number'], '1 234.50'],
            'no places' => ['1234.5', ['number_format' => 'number', 'decimals' => 0], '1 235'],
            'percent' => ['0.1234', ['number_format' => 'percent'], '12.3%'],
            'rand' => ['1234.5', ['number_format' => 'currency'], 'R 1 234.50'],
            'negative rand' => ['-1234.5', ['number_format' => 'currency'], '−R 1 234.50'],
            'euro' => ['1234.5', ['number_format' => 'currency', 'currency' => 'EUR', 'decimals' => 0], '€ 1 235'],
            'date' => ['46330', ['number_format' => 'date'], '2026-11-04'],
            'long date' => ['46330', ['number_format' => 'date', 'date_style' => 'long'], '4 Nov 2026'],
            'day month year' => ['46330.75', ['number_format' => 'date', 'date_style' => 'dmy'], '04/11/2026'],
            'time' => ['0.75', ['number_format' => 'time'], '18:00'],
            'date and time' => ['46330.5', ['number_format' => 'datetime'], '2026-11-04 12:00'],
            'scientific' => ['123456', ['number_format' => 'scientific'], '1.23E+5'],
            'small scientific' => ['0.00123', ['number_format' => 'scientific', 'decimals' => 1], '1.2E-3'],
            'plain text' => ['1234.5', ['number_format' => 'text'], '1234.5'],
            'general' => ['1234567', null, '1 234 567'],
            'a year' => ['2026', null, '2026'],
            'a decimal' => ['1234.5', null, '1 234.5'],
            'general places' => ['1234.5678', ['decimals' => 2], '1 234.57'],
            'text' => ['Hello', null, 'Hello'],
            'leading zero' => ['007', null, '007'],
            'empty' => ['', null, ''],
        ];
    }

    /** @param array<string, mixed>|null $x */
    #[DataProvider('shown')]
    public function test_a_number_reads_on_paper_as_it_does_on_screen(string $value, ?array $x, string $text): void
    {
        $this->assertSame($text, CellDisplay::text($value, $x));
    }
}
