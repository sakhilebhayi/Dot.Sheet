<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\CellHistory;
use App\Models\Comment;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\FormulaEvaluatorService;
use App\Services\StructureVersion;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sheets imported before the import used 0-based indexes have every cell one row and one column
 * off. The detect command only lists them; the fix command needs --tab and --confirm, backs the
 * cells up first and moves positions only.
 */
class OffsetImportRepairTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    private function sheet(string $name = 'Imported'): SheetTab
    {
        return Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'name' => $name])->defaultSheetTab();
    }

    private function putCell(SheetTab $tab, int $row, int $col, ?string $raw, ?string $formula = null): Cell
    {
        return Cell::create(['spreadsheet_id' => $tab->spreadsheet_id, 'sheet_tab_id' => $tab->id, 'row_index' => $row, 'col_index' => $col, 'raw_value' => $raw ?? $formula, 'computed_value' => $formula ? null : $raw, 'formula' => $formula, 'updated_at' => now()]);
    }

    /**
     * What the old importer made of the file [[Name, Qty], [Widget, 3], ['', =SUM(B2,B2)]]: everything at +1, +1.
     */
    private function oldImport(SheetTab $tab): void
    {
        $this->putCell($tab, 1, 1, 'Name');
        $this->putCell($tab, 1, 2, 'Qty');
        $this->putCell($tab, 2, 1, 'Widget');
        $this->putCell($tab, 2, 2, '3');
        $this->putCell($tab, 3, 2, null, '=SUM(B2,B2)');
    }

    private function grid(SheetTab $tab): array
    {
        return Cell::where('sheet_tab_id', $tab->id)->orderBy('row_index')->orderBy('col_index')->get()
            ->mapWithKeys(fn ($c) => ["{$c->row_index}_{$c->col_index}" => $c->raw_value])->all();
    }

    public function test_detect_lists_an_offset_import_and_explains_how_it_decided_without_changing_anything(): void
    {
        $tab = $this->sheet('Q1 import');
        $this->oldImport($tab);
        $before = $this->grid($tab);

        $this->artisan('sheet:detect-offset-imports')
            ->expectsOutputToContain('How this decides')
            ->expectsOutputToContain('Q1 import')
            ->expectsOutputToContain('sheet:fix-offset-imports --tab=<id> --confirm')
            ->assertSuccessful();

        $this->assertSame($before, $this->grid($tab));
        $this->assertSame(0, StructureVersion::current($tab->spreadsheet_id));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_detect_ignores_tabs_built_in_the_editor_and_tabs_with_history_or_coordinates_of_their_own(): void
    {
        $zero = $this->sheet('Starts at zero');
        $this->putCell($zero, 0, 0, 'a');
        $this->putCell($zero, 1, 1, 'b');

        $edited = $this->sheet('Edited later');
        $this->oldImport($edited);
        CellHistory::create(['cell_id' => Cell::where('sheet_tab_id', $edited->id)->first()->id, 'user_id' => $this->user->id, 'old_value' => 'x', 'new_value' => 'Name', 'created_at' => now()]);

        $commented = $this->sheet('Has comment');
        $this->oldImport($commented);
        Comment::create(['spreadsheet_id' => $commented->spreadsheet_id, 'sheet_tab_id' => $commented->id, 'user_id' => $this->user->id, 'row_index' => 1, 'col_index' => 1, 'content' => 'hi']);

        $empty = $this->sheet('Empty');

        $this->artisan('sheet:detect-offset-imports')->expectsOutputToContain('No offset imports found.')->assertSuccessful();
    }

    public function test_fix_without_confirm_changes_nothing(): void
    {
        $tab = $this->sheet();
        $this->oldImport($tab);
        $before = $this->grid($tab);

        $this->artisan('sheet:fix-offset-imports', ['--tab' => $tab->id])->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame($before, $this->grid($tab));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_fix_needs_a_tab(): void
    {
        $this->artisan('sheet:fix-offset-imports', ['--confirm' => true])->assertFailed();
    }

    public function test_fix_with_confirm_backs_up_then_moves_every_cell_and_formulas_still_resolve(): void
    {
        $tab = $this->sheet();
        $this->oldImport($tab);
        $other = $this->sheet('Untouched');
        $this->putCell($other, 5, 5, 'leave me');

        $this->artisan('sheet:fix-offset-imports', ['--tab' => $tab->id, '--confirm' => true])
            ->expectsOutputToContain('Moved 5 cells (1 formulas checked)')
            ->assertSuccessful();

        $this->assertSame(['0_0' => 'Name', '0_1' => 'Qty', '1_0' => 'Widget', '1_1' => '3', '2_1' => '=SUM(B2,B2)'], $this->grid($tab));
        // Formula text is untouched: it already used the file's coordinates, B2 is now the 3.
        $formula = Cell::where('sheet_tab_id', $tab->id)->whereNotNull('formula')->first();
        $this->assertSame('=SUM(B2,B2)', $formula->formula);
        $this->assertEquals(6, (new FormulaEvaluatorService)->evaluate($formula->formula, $tab->spreadsheet_id, $tab->id)['value']);
        // Other tabs untouched; clients that had the sheet open are told to reload.
        $this->assertSame(['5_5' => 'leave me'], $this->grid($other));
        $this->assertSame(1, StructureVersion::current($tab->spreadsheet_id));

        $files = Storage::disk('local')->allFiles('backups/offset-imports');
        $this->assertCount(1, $files);
        $backup = json_decode(Storage::disk('local')->get($files[0]), true);
        $this->assertSame($tab->id, $backup['sheet_tab_id']);
        $this->assertCount(5, $backup['cells']);
        $this->assertSame([1, 1], [$backup['cells'][0]['row_index'], $backup['cells'][0]['col_index']]);
    }

    public function test_fix_works_on_a_dense_block_where_every_target_is_an_existing_cell(): void
    {
        $tab = $this->sheet();
        // Written bottom-right first, so the rows are not in position order on disk.
        foreach (range(6, 1) as $r) {
            foreach (range(6, 1) as $c) {
                $this->putCell($tab, $r, $c, "{$r}.{$c}");
            }
        }

        $this->artisan('sheet:fix-offset-imports', ['--tab' => $tab->id, '--confirm' => true])->assertSuccessful();

        $grid = $this->grid($tab);
        $this->assertCount(36, $grid);
        $this->assertSame('1.1', $grid['0_0']);
        $this->assertSame('6.6', $grid['5_5']);
    }

    public function test_fix_refuses_a_tab_that_does_not_look_like_an_offset_import(): void
    {
        $tab = $this->sheet();
        $this->putCell($tab, 0, 0, 'a');
        $this->putCell($tab, 1, 1, 'b');

        $this->artisan('sheet:fix-offset-imports', ['--tab' => $tab->id, '--confirm' => true])
            ->expectsOutputToContain('is not an offset import')
            ->assertFailed();

        $this->assertSame(['0_0' => 'a', '1_1' => 'b'], $this->grid($tab));
    }

    public function test_a_formula_that_would_not_resolve_rolls_the_whole_fix_back(): void
    {
        $tab = $this->sheet();
        $this->oldImport($tab);
        $this->putCell($tab, 4, 1, null, '=Z99+1');
        $before = $this->grid($tab);

        $this->artisan('sheet:fix-offset-imports', ['--tab' => $tab->id, '--confirm' => true])
            ->expectsOutputToContain('outside the data of the tab after the move; nothing was changed')
            ->assertFailed();

        $this->assertSame($before, $this->grid($tab));
        $this->assertSame(0, StructureVersion::current($tab->spreadsheet_id));
    }

    public function test_the_commands_are_not_run_by_migrations_or_the_scheduler(): void
    {
        $schedule = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode(' ');
        $this->assertStringNotContainsString('offset-imports', $schedule);

        foreach (glob(database_path('migrations/*.php')) as $migration) {
            $this->assertStringNotContainsString('offset-imports', file_get_contents($migration));
        }
    }
}
