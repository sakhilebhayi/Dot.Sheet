<?php

namespace Tests\Feature;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\DataCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Check']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    /** @param list<list<string>> $rows */
    private function fill(array $rows): void
    {
        $edits = [];
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value !== '') {
                    $edits[] = ['row' => $r, 'col' => $c, 'value' => $value];
                }
            }
        }
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, $edits, $this->owner->id);
    }

    /** @return array<string, mixed>|null */
    private function found(string $kind): ?array
    {
        return collect(app(DataCheck::class)->of($this->tab->id))->firstWhere('kind', $kind);
    }

    public function test_a_clean_table_has_nothing_to_say(): void
    {
        $this->fill([['Name', 'Hours'], ['Sipho', '8'], ['Thandi', '7']]);

        $this->assertSame([], app(DataCheck::class)->of($this->tab->id));
    }

    public function test_no_table_says_nothing(): void
    {
        $this->assertSame([], app(DataCheck::class)->of($this->tab->id));
    }

    public function test_repeated_rows_are_named_and_nothing_is_offered_to_delete_them(): void
    {
        $this->fill([['Name', 'Hours'], ['Sipho', '8'], ['Thandi', '7'], ['sipho ', '8']]);

        $found = $this->found('repeats');

        $this->assertSame([3], $found['rows']);
        $this->assertSame('A row appears twice', $found['title']);
        $this->assertSame([], $found['changes']);
    }

    public function test_gaps_are_found_in_a_column_that_is_mostly_full(): void
    {
        $this->fill([['Name', 'Hours', 'Notes'], ['Sipho', '8', ''], ['Thandi', '', ''], ['Lindi', '6', 'late'], ['Ayanda', '5', '']]);

        $found = array_values(array_filter(app(DataCheck::class)->of($this->tab->id), fn (array $f) => $f['kind'] === 'gaps'));

        $this->assertCount(1, $found);
        $this->assertSame('A gap in Hours', $found[0]['title']);
        $this->assertSame([2], $found[0]['rows']);
    }

    public function test_spare_spaces_are_taken_out(): void
    {
        $this->fill([['Name', 'Hours'], ['  Sipho', '8'], ['Thandi  Dube', '7']]);

        $this->assertSame([
            ['row' => 1, 'col' => 0, 'from' => '  Sipho', 'to' => 'Sipho'],
            ['row' => 2, 'col' => 0, 'from' => 'Thandi  Dube', 'to' => 'Thandi Dube'],
        ], $this->found('spaces')['changes']);
    }

    public function test_a_word_written_two_ways_becomes_the_usual_way(): void
    {
        $this->fill([['Dept', 'Hours'], ['Sales', '1'], ['Sales', '2'], ['SALES', '3'], ['Mining', '4']]);

        $this->assertSame([['row' => 3, 'col' => 0, 'from' => 'SALES', 'to' => 'Sales']], $this->found('spellings')['changes']);
    }

    public function test_numbers_held_as_text_become_numbers(): void
    {
        $this->fill([['Item', 'Cost'], ['a', '1200'], ['b', 'R 1 500.50'], ['c', '2,000'], ['d', '700']]);

        $this->assertSame([
            ['row' => 2, 'col' => 1, 'from' => 'R 1 500.50', 'to' => '1500.50'],
            ['row' => 3, 'col' => 1, 'from' => '2,000', 'to' => '2000'],
        ], $this->found('numbers')['changes']);
    }

    public function test_labels_that_hold_digits_are_left_alone(): void
    {
        $this->fill([['Item', 'Code'], ['a', 'Part'], ['b', 'Other'], ['c', '1 200']]);

        $this->assertNull($this->found('numbers'));
    }

    public function test_dates_held_as_text_become_dates_with_the_day_first(): void
    {
        $this->fill([['When', 'Hours'], ['2026-11-04', '1'], ['04/11/2026', '2'], ['31/12/2026', '3']]);

        $changes = $this->found('dates')['changes'];

        $this->assertSame('46330', $changes[0]['to']);
        $this->assertSame('46330', $changes[1]['to']);
        $this->assertSame('46387', $changes[2]['to']);
    }

    public function test_an_impossible_date_is_left_alone(): void
    {
        $this->fill([['When', 'Hours'], ['31/02/2026', '1'], ['30/02/2026', '2']]);

        $this->assertNull($this->found('dates'));
    }

    public function test_a_stray_date_in_a_column_of_words_is_left_alone(): void
    {
        $this->fill([['Note', 'Hours'], ['2026-11-04', '1'], ['words', '2'], ['more', '3']]);

        $this->assertNull($this->found('dates'));
    }

    public function test_a_figure_far_from_the_rest_is_pointed_at_and_nothing_changes_it(): void
    {
        $rows = [['Item', 'Cost']];
        foreach ([10, 11, 12, 13, 14, 15, 16, 17, 9000] as $i => $cost) {
            $rows[] = ["i{$i}", (string) $cost];
        }
        $this->fill($rows);

        $found = $this->found('outliers');

        $this->assertSame([9], $found['rows']);
        $this->assertSame([], $found['changes']);
    }

    public function test_a_few_figures_are_too_few_to_judge(): void
    {
        $this->fill([['Item', 'Cost'], ['a', '10'], ['b', '11'], ['c', '9000']]);

        $this->assertNull($this->found('outliers'));
    }

    public function test_formulas_are_not_touched(): void
    {
        $this->fill([['Name', 'Hours'], ['Sipho', '=1+1'], ['Thandi', '=" x "'], ['Lindi', '=2+2']]);

        $this->assertNull($this->found('spaces'));
    }

    public function test_a_long_list_is_cut_short_and_counted(): void
    {
        $rows = [['Name', 'Hours']];
        foreach (range(1, 30) as $i) {
            $rows[] = ["  N{$i}", '1'];
        }
        $this->fill($rows);

        $found = $this->found('spaces');

        $this->assertCount(20, $found['rows']);
        $this->assertSame(30, $found['count']);
        $this->assertCount(30, $found['changes']);
    }

    public function test_anyone_who_can_read_can_ask_and_nobody_else(): void
    {
        $url = "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/check";
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonPath('findings', []);
        $this->actingAs(User::factory()->withPersonalTeam()->create())->getJson($url)->assertForbidden();
    }
}
