<?php

namespace Tests\Feature;

use App\Livewire\ShowSpreadsheet;
use App\Livewire\SpreadsheetToolbar;
use App\Models\Cell;
use App\Models\SheetProtection;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\SheetProtections;
use App\Services\SheetVersions;
use App\Services\StructureVersion;
use App\Services\WorkbookSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GridProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $editor;

    private User $admin;

    private User $reader;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    private SheetTab $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sheet.structure_ops_enabled' => true]);

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Guarded']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->other = app(WorkbookSheets::class)->add($this->sheet, 'Other');

        foreach (['editor' => 'edit', 'admin' => 'admin', 'reader' => 'view'] as $person => $permission) {
            $this->{$person} = User::factory()->withPersonalTeam()->create();
            $this->sheet->sharedUsers()->attach($this->{$person}->id, ['permission' => $permission]);
        }

        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [
            ['row' => 0, 'col' => 0, 'value' => 'Item'], ['row' => 1, 'col' => 0, 'value' => 'b'], ['row' => 2, 'col' => 0, 'value' => 'a'],
            ['row' => 0, 'col' => 3, 'value' => 'Free'], ['row' => 1, 'col' => 3, 'value' => '1'],
        ], $this->owner->id);
        $this->actingAs($this->editor);
    }

    private function url(string $what, ?SheetTab $tab = null): string
    {
        return "/spreadsheets/{$this->sheet->id}/tabs/".($tab ?? $this->tab)->id."/{$what}";
    }

    private function version(): int
    {
        return StructureVersion::current($this->sheet->id);
    }

    private function protectSheet(?SheetTab $tab = null): void
    {
        app(SheetProtections::class)->protectSheet($this->sheet, ($tab ?? $this->tab)->id, $this->owner->id);
    }

    /** A1:B3: the block with text in it. D stays free. */
    private function protectBlock(): SheetProtection
    {
        app(SheetProtections::class)->protectRange($this->sheet, $this->tab->id, 'A1:B3', 'Item list', $this->owner->id);

        return SheetProtection::latest('id')->firstOrFail();
    }

    private function raw(int $row, int $col, ?SheetTab $tab = null): ?string
    {
        return Cell::where('sheet_tab_id', ($tab ?? $this->tab)->id)->where('row_index', $row)->where('col_index', $col)->value('raw_value');
    }

    /** @param array<string, mixed> $body */
    private function ask(string $what, array $body = [], ?SheetTab $tab = null): TestResponse
    {
        return $this->postJson($this->url($what, $tab), ['version' => $this->version(), ...$body]);
    }

    private function edit(int $row, int $col, string $value): TestResponse
    {
        return $this->ask('cells', ['edits' => [['row' => $row, 'col' => $col, 'value' => $value]]]);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function changesToASheet(): array
    {
        $area = ['r1' => 5, 'r2' => 6, 'c1' => 5, 'c2' => 6];

        return [
            'typing' => ['cells', ['edits' => [['row' => 5, 'col' => 5, 'value' => 'x']]]],
            'pasting' => ['paste', ['from_tab' => 0, 'r1' => 0, 'r2' => 0, 'c1' => 3, 'c2' => 3, 'row' => 8, 'col' => 8, 'rows' => 1, 'cols' => 1]],
            'filling' => ['fill', ['r1' => 0, 'r2' => 0, 'c1' => 3, 'c2' => 3, 'to_r1' => 0, 'to_r2' => 4, 'to_c1' => 3, 'to_c2' => 3]],
            'replacing' => ['replace', ['q' => 'Free', 'with' => 'Taken']],
            'formatting' => ['format', [...$area, 'bold' => true]],
            'resizing' => ['layout', ['axis' => 'col', 'sizes' => ['1' => 200]]],
            'freezing' => ['freeze', ['rows' => 1, 'cols' => 0]],
            'hiding' => ['hidden', ['axis' => 'row', 'hide' => [1], 'show' => []]],
            'sorting' => ['sort', ['r1' => 0, 'r2' => 2, 'c1' => 0, 'c2' => 0, 'key' => 0, 'descending' => true, 'header' => true]],
            'filtering' => ['filter', ['rules' => []]],
            'merging' => ['merge', $area],
            'naming' => ['names', ['name' => 'Things', 'range' => 'A1:A3']],
            'charting' => ['charts', ['title' => 'A', 'type' => 'bar', 'data_range' => 'A1:A3']],
            'renaming' => ['rename', ['name' => 'Taken']],
            'colouring' => ['look/colour', ['colour' => 'teal']],
            'hiding the sheet' => ['look/hidden', ['hidden' => true]],
            'inserting rows' => ['structure', ['action' => 'insert', 'axis' => 'row', 'at' => 1, 'count' => 1]],
            'deleting rows' => ['structure', ['action' => 'delete', 'axis' => 'row', 'at' => 1, 'count' => 1]],
            'deleting the sheet' => ['remove', []],
            'restoring' => ['restore', ['copy' => 0]],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('changesToASheet')]
    public function test_a_protected_sheet_refuses_every_change_from_someone_who_is_not_a_keeper(string $what, array $body): void
    {
        $this->protectSheet();
        $before = Cell::where('sheet_tab_id', $this->tab->id)->count();

        $response = $this->ask($what, $body + (isset($body['from_tab']) ? ['from_tab' => $this->tab->id] : []));

        $response->assertUnprocessable()->assertJsonPath('message', SheetProtections::SHEET_MESSAGE);
        $this->assertSame($before, Cell::where('sheet_tab_id', $this->tab->id)->count());
        $this->assertSame('Sheet1', $this->tab->fresh()->name);
        $this->assertSame('Free', $this->raw(0, 3));
    }

    public function test_a_keeper_is_not_stopped_by_protection(): void
    {
        $this->protectSheet();
        $this->protectBlock();

        foreach ([$this->owner, $this->admin] as $keeper) {
            $this->actingAs($keeper);
            $this->edit(1, 0, 'set by a keeper')->assertOk();
            $this->assertSame('set by a keeper', $this->raw(1, 0));
            $this->edit(1, 0, 'b')->assertOk();
        }
    }

    public function test_the_rest_of_the_workbook_stays_open(): void
    {
        $this->protectSheet();

        $this->ask('cells', ['edits' => [['row' => 0, 'col' => 0, 'value' => 'fine']]], $this->other)->assertOk();

        $this->assertSame('fine', $this->raw(0, 0, $this->other));
    }

    public function test_someone_can_still_talk_about_a_protected_sheet_and_keep_a_copy_of_it(): void
    {
        $this->protectSheet();

        $this->postJson($this->url('comments'), ['row' => 0, 'col' => 0, 'text' => 'Please change this'])->assertSuccessful();
        $this->postJson($this->url('copies'), ['label' => 'Before'])->assertSuccessful();
    }

    public function test_someone_who_cannot_edit_hears_nothing_about_protection(): void
    {
        $this->protectSheet();
        $this->actingAs($this->reader);

        $this->edit(0, 0, 'x')->assertForbidden();
    }

    public function test_protected_cells_refuse_typing_and_the_cells_beside_them_do_not(): void
    {
        $this->protectBlock();

        $this->edit(1, 0, 'x')->assertUnprocessable()->assertJsonPath('message', SheetProtections::CELLS_MESSAGE);
        $this->assertSame('b', $this->raw(1, 0));

        $this->edit(1, 3, '2')->assertOk();
        $this->assertSame('2', $this->raw(1, 3));
    }

    public function test_one_protected_cell_refuses_the_whole_change(): void
    {
        $this->protectBlock();

        $this->ask('cells', ['edits' => [['row' => 1, 'col' => 3, 'value' => '9'], ['row' => 1, 'col' => 0, 'value' => 'x']]])->assertUnprocessable();

        $this->assertSame('1', $this->raw(1, 3));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function changesToCells(): array
    {
        return [
            'pasting over' => ['paste', ['r1' => 0, 'r2' => 0, 'c1' => 3, 'c2' => 3, 'row' => 1, 'col' => 0, 'rows' => 1, 'cols' => 1]],
            'cutting out' => ['paste', ['r1' => 1, 'r2' => 1, 'c1' => 0, 'c2' => 0, 'row' => 8, 'col' => 8, 'rows' => 1, 'cols' => 1, 'cut' => true]],
            'filling over' => ['fill', ['r1' => 0, 'r2' => 0, 'c1' => 3, 'c2' => 3, 'to_r1' => 0, 'to_r2' => 0, 'to_c1' => 0, 'to_c2' => 3]],
            'replacing inside' => ['replace', ['q' => 'Item', 'with' => 'Thing']],
            'formatting' => ['format', ['r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0, 'bold' => true]],
            'sorting' => ['sort', ['r1' => 0, 'r2' => 2, 'c1' => 0, 'c2' => 0, 'key' => 0]],
            'merging across the edge' => ['merge', ['r1' => 0, 'r2' => 0, 'c1' => 1, 'c2' => 3]],
            'deleting a row through them' => ['structure', ['action' => 'delete', 'axis' => 'row', 'at' => 1, 'count' => 1]],
            'deleting a column through them' => ['structure', ['action' => 'delete', 'axis' => 'col', 'at' => 0, 'count' => 1]],
            'deleting the sheet' => ['remove', []],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('changesToCells')]
    public function test_protected_cells_refuse_every_way_of_changing_them(string $what, array $body): void
    {
        $this->protectBlock();

        $this->ask($what, $body + (isset($body['rows']) ? ['from_tab' => $this->tab->id] : []))->assertUnprocessable();

        $this->assertSame('Item', $this->raw(0, 0));
        $this->assertSame('b', $this->raw(1, 0));
        $this->assertNull($this->raw(8, 8));
        $this->assertNotNull($this->tab->fresh());
    }

    public function test_protected_cells_on_another_sheet_stop_a_cut_from_there(): void
    {
        $this->protectBlock();

        $this->ask('paste', ['from_tab' => $this->tab->id, 'r1' => 1, 'r2' => 1, 'c1' => 0, 'c2' => 0, 'row' => 0, 'col' => 0, 'rows' => 1, 'cols' => 1, 'cut' => true], $this->other)->assertUnprocessable();

        $this->assertSame('b', $this->raw(1, 0));
        $this->assertNull($this->raw(0, 0, $this->other));
    }

    public function test_a_protected_sheet_stops_a_cut_from_another_sheet(): void
    {
        $this->protectSheet();

        $this->ask('paste', ['from_tab' => $this->tab->id, 'r1' => 1, 'r2' => 1, 'c1' => 3, 'c2' => 3, 'row' => 0, 'col' => 0, 'rows' => 1, 'cols' => 1, 'cut' => true], $this->other)
            ->assertUnprocessable()->assertJsonPath('message', SheetProtections::SHEET_MESSAGE);

        $this->assertSame('1', $this->raw(1, 3));
    }

    public function test_a_row_deleted_clear_of_protected_cells_is_fine(): void
    {
        $this->protectBlock();

        $this->ask('structure', ['action' => 'delete', 'axis' => 'row', 'at' => 6, 'count' => 1])->assertOk();
    }

    public function test_protected_cells_stay_the_same_cells_when_rows_go_in_above(): void
    {
        $protection = $this->protectBlock();

        $this->ask('structure', ['action' => 'insert', 'axis' => 'row', 'at' => 0, 'count' => 2])->assertOk();

        $this->assertSame(2, $protection->fresh()->start_row);
        $this->assertSame(4, $protection->fresh()->end_row);
        $this->edit(3, 0, 'x')->assertUnprocessable();
        $this->edit(0, 0, 'above')->assertOk();
    }

    public function test_a_keeper_deleting_all_of_the_cells_ends_their_protection(): void
    {
        app(SheetProtections::class)->protectRange($this->sheet, $this->tab->id, 'A2:B2', null, $this->owner->id);
        $this->actingAs($this->owner);

        $this->ask('structure', ['action' => 'delete', 'axis' => 'row', 'at' => 1, 'count' => 1])->assertOk();

        $this->assertSame(0, SheetProtection::count());
    }

    public function test_putting_back_deleted_rows_puts_the_protection_back_where_it_was(): void
    {
        $protection = $this->protectBlock();
        $this->actingAs($this->owner);

        $answer = $this->ask('structure', ['action' => 'delete', 'axis' => 'row', 'at' => 1, 'count' => 1])->assertOk()->json();
        $this->assertSame(1, $protection->fresh()->end_row);

        $this->ask('restore', ['copy' => $answer['undo']['restore']['copy'] ?? $answer['undo']['restore']])->assertOk();

        $this->assertSame(2, $protection->fresh()->end_row);
    }

    public function test_a_copy_cannot_be_put_back_over_protected_cells(): void
    {
        $copy = app(SheetVersions::class)->snapshot($this->sheet->id, $this->tab->id, $this->owner->id, SheetVersions::MANUAL, 'Kept');
        $this->protectBlock();

        $this->ask('restore', ['copy' => $copy->id])->assertUnprocessable()->assertJsonPath('message', SheetProtections::SOME_CELLS_MESSAGE);
    }

    public function test_an_import_cannot_write_over_a_sheet_with_protection_in_it(): void
    {
        $this->protectBlock();
        $file = UploadedFile::fake()->createWithContent('in.csv', "x,y\n1,2\n");

        $this->post("/spreadsheets/{$this->sheet->id}/import", ['file' => $file, 'sheet_tab_id' => $this->tab->id])->assertRedirect()->assertSessionHas('error', SheetProtections::SOME_CELLS_MESSAGE);

        $this->assertSame('Item', $this->raw(0, 0));
    }

    public function test_only_keepers_set_or_remove_protection(): void
    {
        $this->postJson($this->url('protections'), [])->assertForbidden();
        $this->postJson($this->url('protections'), ['range' => 'A1'])->assertForbidden();
        $this->assertSame(0, SheetProtection::count());

        $protection = $this->protectBlock();
        $this->deleteJson($this->url("protections/{$protection->id}"))->assertForbidden();
        $this->assertNotNull($protection->fresh());

        $this->actingAs($this->admin);
        $this->postJson($this->url('protections'), [])->assertOk()->assertJsonPath('state.locked', true);
        $this->deleteJson($this->url("protections/{$protection->id}"))->assertOk()->assertJsonPath('state.ranges', []);
    }

    public function test_a_range_must_be_a_range_and_a_sheet_is_protected_once(): void
    {
        $this->actingAs($this->owner);

        $this->postJson($this->url('protections'), ['range' => 'nonsense'])->assertUnprocessable()->assertJsonPath('message', 'Range must look like A1 or A1:B10.');
        $this->postJson($this->url('protections'), [])->assertOk();
        $this->postJson($this->url('protections'), [])->assertUnprocessable()->assertJsonPath('message', SheetProtections::ALREADY_MESSAGE);
    }

    public function test_a_sheet_holds_a_limited_number_of_ranges(): void
    {
        $this->actingAs($this->owner);
        foreach (range(1, SheetProtections::MAX_ON_A_SHEET) as $row) {
            app(SheetProtections::class)->protectRange($this->sheet, $this->tab->id, "A{$row}", null, $this->owner->id);
        }

        $this->postJson($this->url('protections'), ['range' => 'B1'])->assertUnprocessable();
    }

    public function test_protection_is_reached_only_through_its_own_sheet(): void
    {
        $protection = $this->protectBlock();
        $this->actingAs($this->owner);

        $this->deleteJson($this->url("protections/{$protection->id}", $this->other))->assertNotFound();
        $this->assertNotNull($protection->fresh());

        $elsewhere = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Elsewhere']);
        $this->postJson("/spreadsheets/{$elsewhere->id}/tabs/{$this->tab->id}/protections", [])->assertNotFound();
    }

    public function test_the_list_names_what_is_protected_and_anyone_who_can_open_it_can_read_it(): void
    {
        $this->protectBlock();
        $this->protectSheet();
        $this->actingAs($this->reader);

        $this->getJson($this->url('protections'))->assertOk()
            ->assertJsonPath('protections.0.reference', 'A1:B3')->assertJsonPath('protections.0.label', 'Item list')
            ->assertJsonPath('protections.1.whole', true)->assertJsonPath('state.locked', true)
            ->assertJsonPath('state.ranges.0', ['r1' => 0, 'c1' => 0, 'r2' => 2, 'c2' => 1]);
    }

    public function test_a_stranger_cannot_read_what_is_protected(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->getJson($this->url('protections'))->assertForbidden();
    }

    public function test_the_page_learns_what_it_needs_about_protection(): void
    {
        $this->protectSheet();
        $this->protectBlock();

        $this->getJson($this->url('sheet'))->assertOk()->assertJsonPath('protection.locked', true)->assertJsonPath('protection.ranges.0.r2', 2)
            ->assertJsonPath('tabs.0.locked', true)->assertJsonPath('tabs.1.locked', false);

        $this->get("/spreadsheets/{$this->sheet->id}")->assertOk()->assertViewHas('boot', fn (array $boot) => $boot['canKeep'] === false);
        $this->actingAs($this->admin)->get("/spreadsheets/{$this->sheet->id}")->assertViewHas('boot', fn (array $boot) => $boot['canKeep'] === true);
    }

    public function test_the_classic_editor_sends_an_editor_to_the_new_one_once_there_is_protection(): void
    {
        $this->get("/spreadsheets/{$this->sheet->id}/classic")->assertOk();

        $this->protectBlock();

        $this->get("/spreadsheets/{$this->sheet->id}/classic")->assertRedirect(route('spreadsheets.show', $this->sheet));
        $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}/classic")->assertOk();
        $this->actingAs($this->reader)->get("/spreadsheets/{$this->sheet->id}/classic")->assertOk();
    }

    public function test_a_classic_editor_left_open_stops_working_for_an_editor_once_there_is_protection(): void
    {
        $page = Livewire::test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->sheet->id]);
        $toolbar = Livewire::test(SpreadsheetToolbar::class, ['spreadsheet_id' => $this->sheet->id]);

        $this->protectBlock();

        $page->call('saveCell', 'x')->assertForbidden();
        $toolbar->call('$refresh')->assertForbidden();
        $this->assertSame('Item', $this->raw(0, 0));
    }

    public function test_the_classic_settings_path_cannot_change_a_protected_workbook(): void
    {
        $this->protectBlock();

        $this->putJson("/spreadsheets/{$this->sheet->id}", ['name' => 'Guarded', 'settings' => ['frozen_rows' => 1], 'sheet_tab_id' => $this->tab->id])->assertForbidden();
    }

    public function test_duplicates_start_unprotected(): void
    {
        $this->protectBlock();
        $this->actingAs($this->owner);

        $tab = $this->postJson($this->url('duplicate'))->assertOk()->json('tab');

        $this->assertSame(0, SheetProtection::where('sheet_tab_id', $tab)->count());
    }

    public function test_copies_remember_where_the_protection_stood(): void
    {
        $protection = $this->protectBlock();

        $copy = app(SheetVersions::class)->snapshot($this->sheet->id, $this->tab->id, $this->owner->id, SheetVersions::MANUAL, 'Kept');

        $this->assertSame([[$protection->id, 0, 0, 2, 1]], SpreadsheetVersion::findOrFail($copy->id)->protection_places);
    }
}
