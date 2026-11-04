<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\SheetVersions;
use App\Services\SpreadsheetImportExportService;
use App\Services\StructureVersion;
use App\Services\WorkbookSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GridImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Imported']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function import(string $contents = "a,b\n1,2\n", string $name = 'figures.csv', ?SheetTab $tab = null): TestResponse
    {
        return $this->post("/spreadsheets/{$this->sheet->id}/import", [
            'file' => UploadedFile::fake()->createWithContent($name, $contents),
            'sheet_tab_id' => ($tab ?? $this->tab)->id,
        ], ['Accept' => 'application/json']);
    }

    private function write(string $value = 'kept'): void
    {
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [['row' => 0, 'col' => 0, 'value' => $value]], $this->owner->id);
    }

    private function value(int $row, int $col, ?SheetTab $tab = null): ?string
    {
        return Cell::where('sheet_tab_id', ($tab ?? $this->tab)->id)->where('row_index', $row)->where('col_index', $col)->value('raw_value');
    }

    public function test_the_sheet_is_copied_first_so_an_import_can_be_taken_back(): void
    {
        $this->write('mine');

        $this->import()->assertOk()->assertJson(['success' => true, 'rows' => 2, 'copied' => true]);
        $this->assertSame('a', $this->value(0, 0));

        $copy = SpreadsheetVersion::where('kind', SheetVersions::SAFETY)->firstOrFail();
        $this->assertSame('Before importing figures.csv', $copy->label);

        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/restore", ['version' => StructureVersion::current($this->sheet->id), 'copy' => $copy->id])->assertOk();
        $this->assertSame('mine', $this->value(0, 0));
        $this->assertNull($this->value(1, 1), 'what the file added goes too');
    }

    public function test_an_empty_sheet_needs_no_copy(): void
    {
        $this->import()->assertOk()->assertJson(['success' => true, 'copied' => false]);

        $this->assertSame(0, SpreadsheetVersion::count());
    }

    public function test_a_copy_is_kept_before_a_queued_import_too(): void
    {
        config(['sheet.import_inline_max_cells' => 1]);
        $this->write();

        $this->import()->assertOk()->assertJson(['queued' => true, 'copied' => true]);

        $this->assertSame(1, SpreadsheetVersion::where('kind', SheetVersions::SAFETY)->count());
    }

    public function test_a_file_that_cannot_be_read_leaves_no_copy_behind(): void
    {
        $this->write();

        $this->import('this is not a workbook', 'broken.xlsx')->assertOk()
            ->assertJson(['success' => false, 'error' => SpreadsheetImportExportService::UNREADABLE_MESSAGE, 'copied' => false]);

        $this->assertSame(0, SpreadsheetVersion::count());
        $this->assertSame('kept', $this->value(0, 0));
    }

    public function test_a_failure_says_a_sentence_and_never_the_database_s_own_words(): void
    {
        $this->write();
        DB::statement('create trigger refuse_cells before insert on cells begin select raise(abort, \'secret table detail\'); end');

        $answer = $this->import("x,y\n1,2\n")->assertOk()->assertJson(['success' => false, 'copied' => false])->json();

        $this->assertSame(SpreadsheetImportExportService::NOT_FINISHED_MESSAGE, $answer['error']);
        $this->assertStringNotContainsString('secret', json_encode($answer));
    }

    public function test_a_file_of_the_wrong_kind_or_size_is_refused_in_words(): void
    {
        $this->post("/spreadsheets/{$this->sheet->id}/import", ['file' => UploadedFile::fake()->create('photo.png', 10, 'image/png')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('message', 'Import a CSV or Excel file.');
        $this->post("/spreadsheets/{$this->sheet->id}/import", ['file' => UploadedFile::fake()->create('big.csv', 10241, 'text/csv')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('message', 'That file is larger than 10 MB.');
        $this->post("/spreadsheets/{$this->sheet->id}/import", [], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('message', 'Choose a file to import.');
    }

    public function test_a_text_file_is_refused_in_words(): void
    {
        $this->import('plain words', 'notes.txt')->assertOk()->assertJson(['success' => false, 'error' => 'Import a CSV or Excel file.']);
    }

    public function test_it_goes_to_the_sheet_in_view(): void
    {
        $second = app(WorkbookSheets::class)->add($this->sheet, 'Second');

        $this->import("here\n", 'one.csv', $second)->assertOk();

        $this->assertSame('here', $this->value(0, 0, $second));
        $this->assertNull($this->value(0, 0));
    }

    public function test_the_page_learns_what_an_import_would_write_over(): void
    {
        $url = "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/before-import";

        $this->getJson($url)->assertOk()->assertExactJson(['cells' => 0, 'copied' => true]);

        $this->write();
        $this->getJson($url)->assertOk()->assertExactJson(['cells' => 1, 'copied' => true]);
    }

    public function test_a_sheet_too_large_to_copy_is_imported_without_a_copy_and_says_so(): void
    {
        $this->write();
        $rows = [];
        for ($row = 1; $row <= SheetVersions::AUTO_MAX_CELLS; $row++) {
            $rows[] = ['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $row, 'col_index' => 0, 'raw_value' => 'x', 'computed_value' => 'x', 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('cells')->insert($chunk);
        }

        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/before-import")->assertOk()->assertJsonPath('copied', false);
        $this->import()->assertOk()->assertJson(['success' => true, 'copied' => false]);

        $this->assertSame(0, SpreadsheetVersion::count());
    }

    public function test_someone_who_can_only_read_imports_nothing(): void
    {
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);
        $this->actingAs($reader);

        $this->import()->assertForbidden();
        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/before-import")->assertForbidden();
        $this->assertSame(0, Cell::where('sheet_tab_id', $this->tab->id)->count());
    }
}
