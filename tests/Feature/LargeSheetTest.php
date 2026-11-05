<?php

namespace Tests\Feature;

use App\Jobs\WorkOutSheet;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\Formula\FormulaEngine;
use App\Services\Formula\PrecedentMap;
use App\Services\Formula\Recalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** What keeps a large sheet workable: answers stay right past the point where the shortcuts start. */
class LargeSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->user->id, 'name' => 'Large']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
    }

    /** @param  array<int, array<int, string>>  $grid  row => col => what was typed */
    private function fill(array $grid): void
    {
        $rows = [];
        foreach ($grid as $r => $line) {
            foreach ($line as $c => $value) {
                $formula = str_starts_with($value, '=') ? $value : null;
                $rows[] = ['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $r, 'col_index' => $c, 'raw_value' => $value, 'computed_value' => $formula ? null : $value, 'formula' => $formula, 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('cells')->insert($chunk);
        }
        SheetTab::whereKey($this->tab->id)->update(['formulas_built_at' => null]);
    }

    private function value(int $row, int $col): ?string
    {
        return DB::table('cells')->where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->value('computed_value');
    }

    private function build(): void
    {
        app(Recalculator::class)->ensureBuilt($this->sheet->id, $this->tab->id);
    }

    public function test_many_formulas_that_read_each_other_are_worked_out_in_order(): void
    {
        $grid = [];
        for ($r = 0; $r < 400; $r++) {
            $grid[$r] = [0 => '1', 1 => $r === 0 ? '=A1' : '=B'.$r.'+A'.($r + 1)];
        }
        $grid[400] = [1 => '=SUM(B1:B400)'];
        $this->fill(array_reverse($grid, true));

        $this->build();

        $this->assertSame('400', $this->value(399, 1));
        $this->assertSame((string) (400 * 401 / 2), $this->value(400, 1));
    }

    public function test_a_chain_longer_than_php_can_recurse_comfortably_still_finishes(): void
    {
        $grid = [];
        for ($r = 0; $r < 3000; $r++) {
            $grid[$r] = [0 => $r === 0 ? '1' : '=A'.$r.'+1'];
        }
        $this->fill($grid);

        $this->build();

        $this->assertSame('3000', $this->value(2999, 0));
    }

    public function test_a_cycle_among_many_formulas_is_found_and_the_rest_are_worked_out(): void
    {
        $grid = [];
        for ($r = 0; $r < 100; $r++) {
            $grid[$r] = [0 => '=B'.($r + 1), 1 => $r < 50 ? '=A'.($r + 1).'+1' : '2'];
        }
        $grid[100] = [0 => '=C101', 2 => '=A101'];
        $grid[101] = [0 => '=A102'];
        $this->fill($grid);

        $this->build();

        $this->assertSame('2', $this->value(60, 0));
        foreach ([[10, 0], [10, 1], [100, 0], [100, 2], [101, 0]] as [$r, $c]) {
            $this->assertSame(Recalculator::CIRCULAR_VALUE, $this->value($r, $c), "{$r},{$c}");
        }
    }

    public function test_an_edit_that_feeds_hundreds_of_formulas_reaches_all_of_them_past_the_shortcut(): void
    {
        $grid = [0 => [3 => '2']];
        for ($r = 0; $r < 300; $r++) {
            $grid[$r][0] = (string) $r;
            $grid[$r][1] = '=A'.($r + 1).'*$D$1';
            $grid[$r][2] = '=SUM(B$1:B'.($r + 1).')';
        }
        $this->fill($grid);
        $this->build();
        $this->assertSame((string) (299 * 300), $this->value(299, 2));

        DB::table('cells')->where('sheet_tab_id', $this->tab->id)->where('row_index', 0)->where('col_index', 3)->update(['raw_value' => '3', 'computed_value' => '3']);
        app(Recalculator::class)->cellsWritten($this->sheet->id, $this->tab->id, [[0, 3]]);

        $this->assertSame('897', $this->value(299, 1));
        $this->assertSame((string) (299 * 300 * 3 / 2), $this->value(299, 2));
    }

    public function test_the_map_answers_exactly_what_the_database_answers(): void
    {
        $base = ['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'precedent_tab_id' => $this->tab->id];
        DB::table('cell_precedents')->insert([
            $base + ['row_index' => 0, 'col_index' => 9, 'row_from' => 5, 'row_to' => 5, 'col_from' => 2, 'col_to' => 2],
            $base + ['row_index' => 1, 'col_index' => 9, 'row_from' => 0, 'row_to' => 100, 'col_from' => 2, 'col_to' => 4],
            $base + ['row_index' => 2, 'col_index' => 9, 'row_from' => 50, 'row_to' => 60, 'col_from' => 2, 'col_to' => 2],
            $base + ['row_index' => 3, 'col_index' => 9, 'row_from' => 3, 'row_to' => 4, 'col_from' => 2, 'col_to' => 2],
            $base + ['row_index' => 4, 'col_index' => 9, 'row_from' => 7, 'row_to' => 7, 'col_from' => 3, 'col_to' => 3],
        ]);
        DB::table('cells')->insert(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => 20, 'col_index' => 2, 'raw_value' => '=SEQUENCE(3)', 'formula' => '=SEQUENCE(3)', 'spill_rows' => 3, 'spill_cols' => 2, 'updated_at' => now()]);
        $map = new PrecedentMap;
        $at = fn (int $r1, int $r2, int $c1, int $c2) => collect($map->readers($this->tab->id, $r1, $r2, $c1, $c2))->sort()->values()->all();
        $t = $this->tab->id;

        $this->assertSame(["{$t}:0:9", "{$t}:1:9"], $at(5, 5, 2, 2));
        $this->assertSame(["{$t}:1:9", "{$t}:2:9"], $at(55, 55, 2, 2));
        $this->assertSame(["{$t}:1:9", "{$t}:3:9"], $at(3, 3, 2, 2));
        $this->assertSame(["{$t}:1:9", "{$t}:2:9"], $at(60, 60, 2, 2));
        $this->assertSame([], $at(101, 101, 2, 2));
        $this->assertSame(["{$t}:1:9", "{$t}:4:9"], $at(7, 7, 3, 3));
        $this->assertSame(["{$t}:0:9", "{$t}:1:9", "{$t}:3:9", "{$t}:4:9"], $at(4, 7, 2, 3));
        $this->assertSame([], $at(5, 5, 9, 9));
        $this->assertSame(["{$t}:1:9", "{$t}:20:2"], $at(22, 22, 3, 3), 'A spilled answer reaches over the cells it lands on.');
    }

    public function test_time_running_out_leaves_the_rest_to_a_job_and_the_sheet_says_so(): void
    {
        Queue::fake();
        config(['sheet.recalculate_seconds' => 1, 'sheet.recalculate_always_timed' => true]);
        $grid = [];
        for ($r = 0; $r < 50; $r++) {
            $grid[$r] = [0 => '1', 1 => '=A'.($r + 1)];
        }
        $this->fill($grid);
        $recalculator = app(Recalculator::class);
        (new \ReflectionProperty($recalculator, 'deadline'))->setValue($recalculator, microtime(true) - 1);

        $recalculator->ensureBuilt($this->sheet->id, $this->tab->id);

        Queue::assertPushed(WorkOutSheet::class, fn (WorkOutSheet $job) => $job->sheetTabId === $this->tab->id);
        $this->assertNull($this->tab->fresh()->formulas_built_at);
        $this->assertNull($this->value(0, 1));
        $this->actingAs($this->user)->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/sheet")->assertOk()->assertJsonPath('working', true);
        $this->actingAs($this->user)->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/pulse")->assertOk()->assertJsonPath('working', true);
    }

    public function test_the_job_finishes_the_sheet_and_lets_it_go(): void
    {
        $this->fill([0 => [0 => '4', 1 => '=A1*2']]);
        Cache::put('sheet-finishing:'.$this->tab->id, true, 60);
        $this->assertFalse(app(Recalculator::class)->ensureBuilt($this->sheet->id, $this->tab->id), 'The job has it.');

        (new WorkOutSheet($this->sheet->id, $this->tab->id))->handle(app(Recalculator::class));

        $this->assertSame('8', $this->value(0, 1));
        $this->assertNotNull($this->tab->fresh()->formulas_built_at);
        $this->assertFalse(Recalculator::isFinishing($this->tab->id));
        $this->actingAs($this->user)->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/sheet")->assertJsonPath('working', false);
    }

    public function test_a_job_that_fails_lets_the_sheet_go_too(): void
    {
        Cache::put('sheet-finishing:'.$this->tab->id, true, 60);
        $recalculator = new class(app(FormulaEngine::class)) extends Recalculator
        {
            public function rebuildTab(int $spreadsheetId, int $sheetTabId, array $building = []): array
            {
                throw new \RuntimeException('stopped');
            }
        };
        SheetTab::whereKey($this->tab->id)->update(['formulas_built_at' => null]);

        try {
            $recalculator->finishSheet($this->sheet->id, $this->tab->id);
        } catch (\RuntimeException) {
        }

        $this->assertFalse(Recalculator::isFinishing($this->tab->id));
    }

    public function test_a_formula_reads_all_its_cells_even_when_memory_runs_short_on_every_one(): void
    {
        $this->fill([0 => [0 => '6', 1 => '7', 2 => '=A1*B1'], 1 => [0 => '5', 2 => '=SUM(A1:A2)+B1']]);
        $engine = new class extends FormulaEngine
        {
            protected function memoryIsTight(): bool
            {
                return true;
            }
        };

        $engine->begin($this->sheet->id, []);
        $this->assertSame('42', $engine->evaluate($this->tab->id, 0, 2, '=A1*B1')['value']);
        $this->assertSame('18', $engine->evaluate($this->tab->id, 1, 2, '=SUM(A1:A2)+B1')['value']);
        $engine->finish();
    }

    public function test_memory_running_short_lets_go_of_what_is_held(): void
    {
        $this->fill([0 => [0 => '1', 1 => '2']]);
        $engine = new class extends FormulaEngine
        {
            protected function memoryIsTight(): bool
            {
                return true;
            }
        };
        $held = new \ReflectionProperty(FormulaEngine::class, 'held');

        $engine->begin($this->sheet->id, []);
        $engine->evaluate($this->tab->id, 0, 2, '=A1+B1');
        $once = $held->getValue($engine);
        $engine->evaluate($this->tab->id, 0, 3, '=A1+B1');
        $twice = $held->getValue($engine);
        $engine->finish();

        $this->assertSame(3, $once);
        $this->assertSame(3, $twice, 'What the first formula held was let go.');
    }

    public function test_memory_running_short_does_not_change_the_answers_of_a_sheet(): void
    {
        $grid = [];
        for ($r = 0; $r < 200; $r++) {
            $grid[$r] = [0 => (string) ($r + 1), 1 => '2', 2 => '=A'.($r + 1).'*B'.($r + 1)];
        }
        $grid[200] = [2 => '=SUM(C1:C200)'];
        $this->fill($grid);
        $engine = new class extends FormulaEngine
        {
            protected function memoryIsTight(): bool
            {
                return true;
            }
        };

        (new Recalculator($engine))->ensureBuilt($this->sheet->id, $this->tab->id);

        $this->assertSame('40200', $this->value(200, 2));
    }

    public function test_looking_up_one_cell_does_not_read_the_sheet(): void
    {
        $plan = collect(DB::select('explain query plan select spill_rows from cells where sheet_tab_id = 1 and row_index = 5 and col_index = 3'))->pluck('detail')->implode(' ');

        $this->assertStringContainsString('cells_at', $plan);
    }
}
