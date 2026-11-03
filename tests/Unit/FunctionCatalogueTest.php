<?php

namespace Tests\Unit;

use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\Formula\FunctionCatalogue;
use App\Services\Formula\Recalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing is listed that does not work: every example is typed into a sheet and its answer compared.
 */
class FunctionCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_example_answers_what_the_catalogue_says(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $user->id, 'team_id' => $user->currentTeam->id, 'name' => 'Examples']);
        $tab = $sheet->defaultSheetTab();

        foreach (FunctionCatalogue::EXAMPLE_ROWS as $r => $row) {
            foreach ($row as $c => $value) {
                Cell::create(['spreadsheet_id' => $sheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => $r, 'col_index' => $c, 'raw_value' => (string) $value, 'updated_at' => now()]);
            }
        }

        $wrong = [];
        foreach (FunctionCatalogue::all() as $index => $function) {
            // Far from each other, so an answer that fills cells has room.
            $row = 100 + $index * 10;
            Cell::create(['spreadsheet_id' => $sheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => $row, 'col_index' => 10, 'raw_value' => '', 'formula' => $function['example'], 'updated_at' => now()]);
            app(Recalculator::class)->cellsWritten($sheet->id, $tab->id, [[$row, 10]]);

            $cell = Cell::where('row_index', $row)->where('col_index', 10)->sole();
            if ($cell->computed_value !== $function['answer']) {
                $wrong[] = "{$function['name']}: {$function['example']} gave {$cell->computed_value}, the catalogue says {$function['answer']}";
            }
        }

        $this->assertSame([], $wrong);
    }

    public function test_it_covers_every_function_the_brief_names_in_every_category(): void
    {
        $named = ['SUM', 'AVERAGE', 'COUNT', 'COUNTA', 'COUNTIF', 'COUNTIFS', 'SUMIF', 'SUMIFS', 'IF', 'IFS', 'AND', 'OR', 'IFERROR', 'XLOOKUP', 'VLOOKUP', 'INDEX', 'MATCH', 'FILTER', 'SORT', 'UNIQUE', 'LEFT', 'RIGHT', 'MID', 'TEXT', 'DATE', 'EOMONTH', 'PMT'];

        foreach ($named as $name) {
            $this->assertNotNull(FunctionCatalogue::find($name), $name);
        }

        $this->assertSame(FunctionCatalogue::CATEGORIES, array_values(array_unique(array_column(FunctionCatalogue::all(), 'category'))));
        $this->assertSame('SUM', FunctionCatalogue::find('sum')['name']);
        $this->assertNull(FunctionCatalogue::find('NOSUCH'));
    }
}
