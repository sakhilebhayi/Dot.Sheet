<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\Comment;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange as BookName;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Book;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;
use ZipArchive;

class WorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function book(): Book
    {
        $book = new Book;
        $sales = $book->getActiveSheet()->setTitle('Sales');
        $sales->setCellValue('A1', 'Item')->setCellValue('B1', 'Amount')->setCellValue('C1', 'Share');
        $sales->setCellValue('A2', 'Bolts')->setCellValue('B2', 100)->setCellValue('C2', '=B2/SUM(B$2:B$3)');
        $sales->setCellValue('A3', 'Nuts')->setCellValue('B3', 300)->setCellValue('C3', '=B3/SUM(B$2:B$3)');

        $sales->getStyle('A1:C1')->getFont()->setBold(true);
        $sales->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('8F4A0E');
        $sales->getStyle('A3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FBF0BF');
        $sales->getStyle('B2:B3')->getNumberFormat()->setFormatCode('"R" #,##0.00');
        $sales->getStyle('C2:C3')->getNumberFormat()->setFormatCode('0.0%');
        $sales->getStyle('B1')->getAlignment()->setHorizontal('right');

        $sales->getColumnDimension('A')->setWidth(30);
        $sales->getRowDimension(1)->setRowHeight(30);
        $sales->getColumnDimension('E')->setVisible(false);
        $sales->getRowDimension(9)->setVisible(false);
        $sales->mergeCells('A5:B5');
        $sales->freezePane('B2');
        $sales->getTabColor()->setRGB('0E8AA0');

        $list = new DataValidation;
        $list->setType(DataValidation::TYPE_LIST)->setFormula1('"Bolts,Nuts"');
        $sales->setDataValidation('A2:A3', $list);
        $range = new DataValidation;
        $range->setType(DataValidation::TYPE_DECIMAL)->setOperator(DataValidation::OPERATOR_BETWEEN)->setFormula1('0')->setFormula2('1000');
        $sales->setDataValidation('B2:B3', $range);

        $rule = new Conditional;
        $rule->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_GREATERTHAN)->addCondition('200');
        $rule->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB('F7E0DA');
        $sales->getStyle('B2:B3')->setConditionalStyles([$rule]);

        $sales->getComment('A1')->setAuthor('Thandi')->getText()->createTextRun('Check these names');

        $totals = $book->createSheet()->setTitle('Cash flow');
        $totals->setCellValue('A1', 'Total')->setCellValue('B1', '=SUM(Sales!B2:B3)');

        $hidden = $book->createSheet()->setTitle('Workings');
        $hidden->setCellValue('A1', 'kept')->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $book->addNamedRange(new BookName('Takings', $sales, '$B$2:$B$3'));

        return $book;
    }

    private function save(Book $book, string $type = 'Xlsx', string $extension = 'xlsx'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').".{$extension}";
        IOFactory::createWriter($book, $type)->save($path);
        $this->files[] = $path;

        return $path;
    }

    private function send(string $path, string $name = 'Year end.xlsx'): Spreadsheet
    {
        $this->post(route('spreadsheets.from-file'), ['file' => new UploadedFile($path, $name, null, null, true)])->assertRedirect();

        return Spreadsheet::latest('id')->firstOrFail();
    }

    private function tab(Spreadsheet $spreadsheet, string $name): SheetTab
    {
        return $spreadsheet->sheetTabs()->where('name', $name)->sole();
    }

    private function cell(SheetTab $tab, int $row, int $col): ?Cell
    {
        return Cell::where('sheet_tab_id', $tab->id)->where('row_index', $row)->where('col_index', $col)->first();
    }

    private function open(SheetTab $tab): void
    {
        $this->getJson("/spreadsheets/{$tab->spreadsheet_id}/tabs/{$tab->id}/sheet")->assertOk();
    }

    public function test_every_sheet_comes_across_in_order_named_after_the_file(): void
    {
        $spreadsheet = $this->send($this->save($this->book()));

        $this->assertSame('Year end', $spreadsheet->name);
        $this->assertSame(['Sales', 'Cash flow', 'Workings'], $spreadsheet->sheetTabs()->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame($this->owner->id, $spreadsheet->owner_id);
    }

    public function test_formulas_stay_formulas_and_work_across_sheets(): void
    {
        $spreadsheet = $this->send($this->save($this->book()));
        $totals = $this->tab($spreadsheet, 'Cash flow');

        $this->assertSame('=SUM(Sales!B2:B3)', $this->cell($totals, 0, 1)->formula);

        $this->open($totals);
        $this->assertSame('400', $this->cell($totals, 0, 1)->fresh()->computed_value);
    }

    public function test_formatting_comes_across(): void
    {
        $sales = $this->tab($this->send($this->save($this->book())), 'Sales');

        $this->assertTrue($this->cell($sales, 0, 0)->formatting['bold']);
        $this->assertSame(['italic' => true, 'font_color' => '#8F4A0E'], array_intersect_key($this->cell($sales, 1, 0)->formatting, ['italic' => 1, 'font_color' => 1]));
        $this->assertSame('#FBF0BF', $this->cell($sales, 2, 0)->formatting['bg_color']);
        $this->assertSame(['number_format' => 'currency', 'currency' => 'ZAR', 'decimals' => 2], $this->cell($sales, 1, 1)->formatting);
        $this->assertSame(['number_format' => 'percent', 'decimals' => 1], $this->cell($sales, 1, 2)->formatting);
        $this->assertSame('right', $this->cell($sales, 0, 1)->formatting['align']);
    }

    public function test_the_shape_of_each_sheet_comes_across(): void
    {
        $sales = $this->tab($this->send($this->save($this->book())), 'Sales');
        $layout = $sales->layout;

        $this->assertSame(215, $layout['col_widths'][0]);
        $this->assertSame(40, $layout['row_heights'][0]);
        $this->assertContains(4, $layout['hidden_cols']);
        $this->assertContains(8, $layout['hidden_rows']);
        $this->assertSame([['r1' => 4, 'c1' => 0, 'r2' => 4, 'c2' => 1]], $layout['merges']);
        $this->assertSame(1, $layout['frozen_rows']);
        $this->assertSame(1, $layout['frozen_cols']);
    }

    public function test_allowed_values_and_colour_rules_come_across(): void
    {
        $layout = $this->tab($this->send($this->save($this->book())), 'Sales')->layout;

        $list = collect($layout['validation_rules'])->firstWhere('type', 'list');
        $this->assertSame('Bolts, Nuts', $list['items']);
        $this->assertSame([1, 0, 2, 0], [$list['row'], $list['col'], $list['row2'], $list['col2']]);
        $range = collect($layout['validation_rules'])->firstWhere('type', 'number');
        $this->assertEquals([0, 1000], [$range['min'], $range['max']]);

        $rule = $layout['conditional_formatting_rules'][0];
        $this->assertSame(['>', '200', '#F7E0DA', 1], [$rule['operator'], $rule['value'], $rule['color'], $rule['col']]);
    }

    public function test_comments_names_colours_and_hidden_sheets_come_across(): void
    {
        $spreadsheet = $this->send($this->save($this->book()));
        $sales = $this->tab($spreadsheet, 'Sales');

        $comment = Comment::where('sheet_tab_id', $sales->id)->sole();
        $this->assertSame('Thandi: Check these names', $comment->content);
        $this->assertSame($this->owner->id, $comment->user_id);
        $this->assertSame('Takings', NamedRange::where('sheet_tab_id', $sales->id)->sole()->name);
        $this->assertSame('teal', $sales->color);
        $this->assertTrue($this->tab($spreadsheet, 'Workings')->hidden);
        $this->assertFalse($sales->hidden);
    }

    public function test_the_report_counts_what_came_across_and_nothing_was_lost(): void
    {
        $report = $this->send($this->save($this->book()))->settings['import_report'];

        $this->assertSame(3, $report['sheets']);
        $this->assertSame(3, $report['formulas']);
        $this->assertSame(1, $report['names']);
        $this->assertSame([], $report['left']);
        $this->assertSame([], $report['unknown']);
    }

    public function test_a_page_opened_after_an_import_shows_the_report_once(): void
    {
        $this->post(route('spreadsheets.from-file'), ['file' => new UploadedFile($this->save($this->book()), 'a.xlsx', null, null, true)])->assertSessionHas('import_report', true);
        $spreadsheet = Spreadsheet::latest('id')->firstOrFail();

        $this->get("/spreadsheets/{$spreadsheet->id}")->assertViewHas('boot', fn (array $boot) => $boot['showReport'] === true && $boot['importReport']['sheets'] === 3);
        $this->get("/spreadsheets/{$spreadsheet->id}")->assertViewHas('boot', fn (array $boot) => $boot['showReport'] === false && $boot['importReport']['sheets'] === 3);
    }

    public function test_what_cannot_come_across_is_named(): void
    {
        $book = $this->book();
        $sales = $book->getSheet(0);

        $cells = new DataValidation;
        $cells->setType(DataValidation::TYPE_LIST)->setFormula1('$A$2:$A$3');
        $sales->setDataValidation('D2:D3', $cells);

        $scale = new Conditional;
        $scale->setConditionType(Conditional::CONDITION_DATABAR);
        $sales->getStyle('C2:C3')->setConditionalStyles([$scale]);

        $sales->getCell('A7')->setValue('site')->getHyperlink()->setUrl('https://example.com');

        $path = $this->save($book);
        $this->addToZip($path, 'xl/vbaProject.bin', 'macro');
        $this->addToZip($path, 'xl/pivotTables/pivotTable1.xml', '<x/>');

        $report = $this->send($path)->settings['import_report'];
        $left = array_column($report['left'], 'what');

        $this->assertContains('a pivot table', $left);
        $this->assertContains('macros', $left);
        $this->assertContains('a link in a cell', $left);
        $this->assertContains('a rule about allowed values', $left);
        $this->assertContains('a colour rule', $left);
    }

    public function test_many_of_a_thing_are_counted(): void
    {
        $book = $this->book();
        foreach (['A7', 'A8'] as $at) {
            $book->getSheet(0)->getCell($at)->setValue('site')->getHyperlink()->setUrl('https://example.com');
        }

        $this->assertContains('2 links in cells', array_column($this->send($this->save($book))->settings['import_report']['left'], 'what'));
    }

    private function addToZip(string $path, string $entry, string $content): void
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString($entry, $content);
        $zip->close();
    }

    public function test_a_link_to_another_file_keeps_its_last_answer(): void
    {
        $path = $this->save($this->book());
        $this->replaceInZip($path, 'xl/worksheets/sheet3.xml', '<c r="A1" t="s"><v>', '<c r="B2"><f>[1]Other!A1</f><v>77</v></c><c r="A1" t="s"><v>');

        $spreadsheet = $this->send($path);
        $workings = $this->tab($spreadsheet, 'Workings');

        $this->assertNull(Cell::where('sheet_tab_id', $workings->id)->whereNotNull('formula')->first());
        $this->assertContains('a formula that reads another file', array_column($spreadsheet->settings['import_report']['left'], 'what'));
    }

    private function replaceInZip(string $path, string $entry, string $find, string $replace): void
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName($entry);
        $this->assertStringContainsString($find, $xml);
        $zip->addFromString($entry, str_replace($find, $replace, $xml));
        $zip->close();
    }

    public function test_newer_functions_lose_the_prefix_excel_stores_them_with(): void
    {
        $book = new Book;
        $book->getActiveSheet()->setCellValue('A1', 'x')->setCellValue('B1', 5)->setCellValue('C1', 'placeholder');
        $path = $this->save($book);
        $this->replaceInZip($path, 'xl/worksheets/sheet1.xml', '<c r="C1" t="s">', '<c r="D1"><f>_xlfn._xlws.FILTER(A1:A1,B1:B1&gt;0)</f><v>0</v></c><c r="E1"><f>_xlfn.XLOOKUP("x",A1:A1,B1:B1)</f><v>5</v></c><c r="C1" t="s">');

        $tab = $this->send($path)->sheetTabs()->sole();

        $this->assertSame('=FILTER(A1:A1,B1:B1>0)', $this->cell($tab, 0, 3)->formula);
        $this->assertSame('=XLOOKUP("x",A1:A1,B1:B1)', $this->cell($tab, 0, 4)->formula);
    }

    public function test_a_function_nothing_here_can_work_out_is_named_and_the_formula_kept(): void
    {
        $book = new Book;
        $book->getActiveSheet()->setCellValue('A1', '=MADEUPFUNCTION(1)');

        $spreadsheet = $this->send($this->save($book));

        $this->assertSame(['MADEUPFUNCTION'], $spreadsheet->settings['import_report']['unknown']);
        $this->assertSame('=MADEUPFUNCTION(1)', $this->cell($spreadsheet->sheetTabs()->sole(), 0, 0)->formula);
    }

    public function test_text_that_looks_like_a_formula_is_not_run(): void
    {
        $book = new Book;
        $book->getActiveSheet()->setCellValueExplicit('A1', '=SUM(1,2)', 's');

        $cell = Cell::where('spreadsheet_id', $this->send($this->save($book))->id)->sole();

        $this->assertNull($cell->formula);
        $this->assertSame('=SUM(1,2)', $cell->raw_value);
    }

    public function test_dates_and_times_read_as_their_kinds(): void
    {
        $book = new Book;
        $sheet = $book->getActiveSheet();
        foreach ([['A1', 'yyyy-mm-dd', 46330], ['A2', 'hh:mm', 0.5], ['A3', 'yyyy-mm-dd hh:mm', 46330.5], ['A4', '0.00E+00', 1234], ['A5', '$#,##0.00', 5], ['A6', '#,##0', 5], ['A7', '@', 5]] as [$at, $code, $value]) {
            $sheet->setCellValue($at, $value);
            $sheet->getStyle($at)->getNumberFormat()->setFormatCode($code);
        }

        $tab = $this->send($this->save($book))->sheetTabs()->sole();
        $kinds = Cell::where('sheet_tab_id', $tab->id)->orderBy('row_index')->get()->map(fn (Cell $cell) => $cell->formatting['number_format'] ?? 'general')->all();

        $this->assertSame(['date', 'time', 'datetime', 'scientific', 'currency', 'number', 'text'], $kinds);
    }

    public function test_a_sheet_with_every_sheet_hidden_still_shows_one(): void
    {
        $book = new Book;
        $book->getActiveSheet()->setTitle('One')->setCellValue('A1', 'x');
        $book->createSheet()->setTitle('Two')->setCellValue('A1', 'y');
        $path = $this->save($book);
        $this->replaceInZip($path, 'xl/workbook.xml', '<sheet name="One"', '<sheet state="hidden" name="One"');
        $this->replaceInZip($path, 'xl/workbook.xml', '<sheet name="Two"', '<sheet state="hidden" name="Two"');

        $spreadsheet = $this->send($path);

        $this->assertSame(1, $spreadsheet->sheetTabs()->where('hidden', false)->count());
    }

    public function test_an_ods_file_comes_across_too(): void
    {
        $spreadsheet = $this->send($this->save($this->book(), 'Ods', 'ods'), 'Year end.ods');

        $this->assertSame(['Sales', 'Cash flow', 'Workings'], $spreadsheet->sheetTabs()->orderBy('sort_order')->pluck('name')->all());
        $totals = $this->tab($spreadsheet, 'Cash flow');
        $this->open($totals);
        $this->assertSame('400', $this->cell($totals, 0, 1)->fresh()->computed_value);
    }

    public function test_an_old_excel_file_comes_across_too(): void
    {
        $spreadsheet = $this->send($this->save($this->book(), 'Xls', 'xls'), 'Year end.xls');

        $this->assertSame(3, $spreadsheet->sheetTabs()->count());
        $this->assertTrue($this->cell($this->tab($spreadsheet, 'Sales'), 0, 0)->formatting['bold']);
    }

    public function test_a_tsv_file_lands_in_tabs(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.tsv';
        file_put_contents($path, "Truck\tLitres\nT1\t40\n");
        $this->files[] = $path;

        $spreadsheet = $this->send($path, 'Fuel.tsv');

        $this->assertSame('Litres', $this->cell($spreadsheet->sheetTabs()->sole(), 0, 1)?->raw_value);
    }

    public function test_a_file_too_large_to_read_whole_comes_in_as_values_and_says_so(): void
    {
        config(['sheet.import_whole_max_cells' => 2]);

        $this->post(route('spreadsheets.from-file'), ['file' => new UploadedFile($this->save($this->book()), 'big.xlsx', null, null, true)])
            ->assertRedirect()->assertSessionHas('success', 'That file is too large to read whole, so its first sheet came in as values, without formulas or formatting.');

        $this->assertSame(1, Spreadsheet::sole()->sheetTabs()->count());
    }

    public function test_a_broken_file_leaves_nothing_behind(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        file_put_contents($path, 'not a workbook');
        $this->files[] = $path;

        $this->post(route('spreadsheets.from-file'), ['file' => new UploadedFile($path, 'a.xlsx', null, null, true)])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, Spreadsheet::count());
    }

    public function test_a_sheet_name_this_product_would_refuse_is_made_safe(): void
    {
        $book = new Book;
        $book->getActiveSheet()->setTitle('Q1 & Q2')->setCellValue('A1', 'x');

        $this->assertSame(['Q1 & Q2'], $this->send($this->save($book))->sheetTabs()->pluck('name')->all());
    }

    public function test_a_number_format_nothing_like_ours_is_left_as_it_comes(): void
    {
        $book = new Book;
        $book->getActiveSheet()->setCellValue('A1', 5);
        $book->getActiveSheet()->getStyle('A1')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_GENERAL);

        $this->assertNull($this->cell($this->send($this->save($book))->sheetTabs()->sole(), 0, 0)->formatting);
    }
}
