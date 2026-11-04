<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GridFillFindTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Fill']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function url(string $path, ?SheetTab $tab = null): string
    {
        return "/spreadsheets/{$this->sheet->id}/tabs/".($tab ?? $this->tab)->id."/{$path}";
    }

    /**
     * @param  list<array{0: int, 1: int, 2: string}>  $cells
     */
    private function enter(array $cells, ?SheetTab $tab = null): void
    {
        $this->postJson($this->url('cells', $tab), [
            'version' => StructureVersion::current($this->sheet->id),
            'edits' => array_map(fn (array $c) => ['row' => $c[0], 'col' => $c[1], 'value' => $c[2]], $cells),
        ])->assertOk();
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int}  $from  r1, r2, c1, c2
     * @param  array{0: int, 1: int, 2: int, 3: int}  $to
     */
    private function fillArea(array $from, array $to): TestResponse
    {
        return $this->postJson($this->url('fill'), [
            'version' => StructureVersion::current($this->sheet->id),
            'r1' => $from[0], 'r2' => $from[1], 'c1' => $from[2], 'c2' => $from[3],
            'to_r1' => $to[0], 'to_r2' => $to[1], 'to_c1' => $to[2], 'to_c2' => $to[3],
        ]);
    }

    private function held(int $row, int $col, ?SheetTab $tab = null): ?string
    {
        $cell = Cell::where('sheet_tab_id', ($tab ?? $this->tab)->id)->where('row_index', $row)->where('col_index', $col)->first();

        return $cell === null ? null : ($cell->formula ?? $cell->raw_value);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function undo(array $answer): void
    {
        foreach ($answer['undo'] as $group) {
            $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$group['tab']}/cells", [
                'version' => StructureVersion::current($this->sheet->id),
                'edits' => $group['edits'],
            ])->assertOk();
        }
    }

    /**
     * @return array<string, array{list<string>, list<string>}>
     */
    public static function series(): array
    {
        return [
            'one number repeats' => [['5'], ['5', '5']],
            'evenly spaced numbers count on' => [['2', '4'], ['6', '8']],
            'decimals' => [['0.1', '0.2'], ['0.3', '0.4']],
            'uneven numbers repeat' => [['1', '2', '4'], ['1', '2']],
            'months' => [['Jan'], ['Feb', 'Mar']],
            'months wrap' => [['Nov', 'Dec'], ['Jan', 'Feb']],
            'full months' => [['October'], ['November', 'December']],
            'days' => [['Fri'], ['Sat', 'Sun']],
            'capitals stay' => [['MON'], ['TUE', 'WED']],
            'every second month' => [['Jan', 'Mar'], ['May', 'Jul']],
            'text ending in a number' => [['Q1'], ['Q2', 'Q3']],
            'zero padded' => [['Unit 007'], ['Unit 008', 'Unit 009']],
            'stepped' => [['Week 2', 'Week 4'], ['Week 6', 'Week 8']],
            'dates' => [['2026-02-27'], ['2026-02-28', '2026-03-01']],
            'dates by week' => [['2026-01-01', '2026-01-08'], ['2026-01-15', '2026-01-22']],
            'plain text repeats' => [['Rent'], ['Rent', 'Rent']],
            'a repeating pair' => [['Yes', 'No'], ['Yes', 'No']],
        ];
    }

    /**
     * @param  list<string>  $start
     * @param  list<string>  $expected
     */
    #[DataProvider('series')]
    public function test_filling_down_continues_or_repeats(array $start, array $expected): void
    {
        $this->enter(array_map(fn (int $i) => [$i, 0, $start[$i]], array_keys($start)));
        $last = count($start) - 1;

        $this->fillArea([0, $last, 0, 0], [0, $last + count($expected), 0, 0])->assertOk();

        foreach ($expected as $i => $value) {
            $this->assertSame($value, $this->held($last + 1 + $i, 0));
        }
    }

    public function test_filling_right_and_upward(): void
    {
        $this->enter([[5, 2, 'Jan'], [5, 3, 'Feb']]);

        $this->fillArea([5, 5, 2, 3], [5, 5, 2, 5])->assertOk();
        $this->assertSame(['Mar', 'Apr'], [$this->held(5, 4), $this->held(5, 5)]);

        $this->enter([[8, 0, 'Q3']]);
        $this->fillArea([8, 8, 0, 0], [6, 8, 0, 0])->assertOk();
        $this->assertSame(['Q1', 'Q2'], [$this->held(6, 0), $this->held(7, 0)]);
    }

    public function test_formulas_follow_their_cell_and_anchors_stay(): void
    {
        $this->enter([[0, 0, '1'], [1, 0, '2'], [2, 0, '3'], [0, 1, '=A1*$A$1']]);

        $this->fillArea([0, 0, 1, 1], [0, 2, 1, 1])->assertOk();

        $this->assertSame('=A2*$A$1', $this->held(1, 1));
        $this->assertSame('=A3*$A$1', $this->held(2, 1));
        $this->assertSame('3', Cell::where('row_index', 2)->where('col_index', 1)->value('computed_value'));
    }

    public function test_filling_can_be_undone_and_keeps_formatting(): void
    {
        $this->enter([[0, 0, 'Q1']]);
        $this->postJson($this->url('format'), ['version' => StructureVersion::current($this->sheet->id), 'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0, 'bold' => true]);

        $answer = $this->fillArea([0, 0, 0, 0], [0, 2, 0, 0])->assertOk()->assertJsonPath('area.r2', 2)->json();

        $this->assertSame(['bold' => true], Cell::where('row_index', 2)->value('formatting'));

        $this->undo($answer);

        $this->assertNull($this->held(1, 0));
        $this->assertNull($this->held(2, 0));
        $this->assertSame('Q1', $this->held(0, 0));
    }

    public function test_a_fill_goes_one_way_and_has_a_limit(): void
    {
        $this->fillArea([1, 1, 1, 1], [0, 2, 0, 2])->assertUnprocessable();
        $this->fillArea([1, 1, 1, 1], [1, 1, 1, 1])->assertUnprocessable();
        $this->fillArea([1, 1, 1, 1], [2, 3, 1, 1])->assertUnprocessable();
        $this->fillArea([0, 0, 0, 0], [0, 6000, 0, 0])->assertUnprocessable();
    }

    public function test_find_looks_in_contents_and_in_answers(): void
    {
        $this->enter([[0, 0, 'Sales total'], [1, 0, 'sales'], [2, 0, '=1+1'], [3, 0, '=SUM(A3)']]);

        $this->getJson($this->url('find').'?q=sales')->assertOk()->assertJsonCount(2, 'matches');
        $this->getJson($this->url('find').'?q=sales&case=1')->assertOk()->assertJsonCount(1, 'matches');
        $this->getJson($this->url('find').'?q=sales&whole=1')->assertOk()->assertJsonCount(1, 'matches')->assertJsonPath('matches.0.r', 1);
        $this->getJson($this->url('find').'?q=SUM')->assertOk()->assertJsonPath('matches.0.r', 3);
        // 2 is the answer in A3, and the text of the reference in A4.
        $this->getJson($this->url('find').'?q=2&whole=1')->assertOk()->assertJsonPath('matches.0.r', 2);
    }

    public function test_find_treats_wildcards_as_plain_text(): void
    {
        $this->enter([[0, 0, '100%'], [1, 0, '100'], [2, 0, 'a_b'], [3, 0, 'axb']]);

        $this->getJson($this->url('find').'?q='.urlencode('0%'))->assertOk()->assertJsonCount(1, 'matches');
        $this->getJson($this->url('find').'?q=a_b')->assertOk()->assertJsonCount(1, 'matches');
    }

    public function test_find_reaches_every_sheet_only_when_asked(): void
    {
        $other = SheetTab::create(['spreadsheet_id' => $this->sheet->id, 'name' => 'Other', 'sort_order' => 1]);
        $this->enter([[0, 0, 'Rent']]);
        $this->enter([[4, 2, 'Rent']], $other);

        $this->getJson($this->url('find').'?q=rent')->assertOk()->assertJsonCount(1, 'matches');
        $this->getJson($this->url('find').'?q=rent&all=1')->assertOk()->assertJsonCount(2, 'matches')
            ->assertJsonPath('matches.1', ['tab' => $other->id, 'r' => 4, 'c' => 2]);
    }

    public function test_replace_all_changes_what_cells_hold_and_can_be_undone(): void
    {
        $this->enter([[0, 0, 'Rent due'], [1, 0, 'rent paid'], [2, 0, '=IF(1=1,"rent","x")']]);

        $answer = $this->postJson($this->url('replace'), ['version' => StructureVersion::current($this->sheet->id), 'q' => 'rent', 'with' => 'Lease'])
            ->assertOk()->assertJsonPath('replaced', 3)->json();

        $this->assertSame('Lease due', $this->held(0, 0));
        $this->assertSame('Lease paid', $this->held(1, 0));
        $this->assertSame('=IF(1=1,"Lease","x")', $this->held(2, 0));
        $this->assertSame('Lease', Cell::where('row_index', 2)->value('computed_value'));

        $this->undo($answer);

        $this->assertSame('Rent due', $this->held(0, 0));
        $this->assertSame('=IF(1=1,"rent","x")', $this->held(2, 0));
    }

    public function test_replace_one_changes_that_cell_only(): void
    {
        $this->enter([[0, 0, 'Rent'], [1, 0, 'Rent']]);

        $this->postJson($this->url('replace'), ['version' => StructureVersion::current($this->sheet->id), 'q' => 'Rent', 'with' => 'Lease', 'only' => ['tab' => $this->tab->id, 'r' => 1, 'c' => 0]])
            ->assertOk()->assertJsonPath('replaced', 1);

        $this->assertSame(['Rent', 'Lease'], [$this->held(0, 0), $this->held(1, 0)]);
    }

    public function test_replacement_text_is_taken_literally(): void
    {
        $this->enter([[0, 0, 'cost']]);

        $this->postJson($this->url('replace'), ['version' => StructureVersion::current($this->sheet->id), 'q' => 'cost', 'with' => '$1 \\0'])->assertOk();

        $this->assertSame('$1 \\0', $this->held(0, 0));
    }

    public function test_replace_leaves_answers_that_only_show_the_text(): void
    {
        $this->enter([[0, 0, '=SEQUENCE(2)'], [5, 0, '=1+1']]);

        $this->postJson($this->url('replace'), ['version' => StructureVersion::current($this->sheet->id), 'q' => '2', 'with' => '9', 'whole' => true])
            ->assertOk()->assertJsonPath('replaced', 0);

        $this->assertSame('=1+1', $this->held(5, 0));
    }

    public function test_a_viewer_can_find_but_not_change(): void
    {
        $this->enter([[0, 0, 'Rent']]);
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);
        $this->actingAs($viewer);

        $this->getJson($this->url('find').'?q=Rent')->assertOk()->assertJsonCount(1, 'matches');
        $this->postJson($this->url('replace'), ['version' => StructureVersion::current($this->sheet->id), 'q' => 'Rent', 'with' => 'x'])->assertForbidden();
        $this->fillArea([0, 0, 0, 0], [0, 1, 0, 0])->assertForbidden();

        $this->assertSame('Rent', $this->held(0, 0));
    }

    public function test_a_stranger_finds_nothing(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->getJson($this->url('find').'?q=Rent')->assertForbidden();
    }
}
