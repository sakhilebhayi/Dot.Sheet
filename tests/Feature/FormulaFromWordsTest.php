<?php

namespace Tests\Feature;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormulaFromWordsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Words']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);

        $rows = [
            ['Department', 'Item', 'Expense', 'Unit price', 'Status'],
            ['Sales', 'Fuel', '100', '2', 'Open'],
            ['Mining', 'Bolts', '200', '3', 'Closed'],
            ['Sales', 'Rent', '300', '4', 'Open'],
            ['Admin', 'Paper', '400', '5', 'Open'],
        ];
        $edits = [];
        foreach ($rows as $row => $cells) {
            foreach ($cells as $col => $value) {
                $edits[] = ['row' => $row, 'col' => $col, 'value' => $value];
            }
        }
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, $edits, $this->owner->id);
    }

    private function ask(string $sentence): TestResponse
    {
        return $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/from-words?sentence=".rawurlencode($sentence));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function sentences(): array
    {
        return [
            'a total' => ['total expense', '=SUM($C$2:$C$5)'],
            'a plural' => ['Add up the expenses', '=SUM($C$2:$C$5)'],
            'an average' => ['the average expense', '=AVERAGE($C$2:$C$5)'],
            'the longest heading wins' => ['highest unit price', '=MAX($D$2:$D$5)'],
            'lowest' => ['lowest expense', '=MIN($C$2:$C$5)'],
            'how many rows' => ['how many expenses', '=COUNTA($C$2:$C$5)'],
            'a named condition' => ['total expense where status is open', '=SUMIFS($C$2:$C$5,$E$2:$E$5,"open")'],
            'a value in the data' => ['total expense for sales', '=SUMIFS($C$2:$C$5,$A$2:$A$5,"Sales")'],
            'an average with a condition' => ['average expense for mining', '=AVERAGEIFS($C$2:$C$5,$A$2:$A$5,"Mining")'],
            'highest with a condition' => ['highest expense where status is closed', '=MAXIFS($C$2:$C$5,$E$2:$E$5,"closed")'],
            'a count with a condition' => ['how many where status is open', '=COUNTIFS($E$2:$E$5,"open")'],
        ];
    }

    #[DataProvider('sentences')]
    public function test_a_sentence_becomes_a_formula(string $sentence, string $formula): void
    {
        $this->ask($sentence)->assertOk()->assertJsonPath('formula', $formula);
    }

    public function test_the_answer_comes_with_it_in_plain_words(): void
    {
        $this->ask('total expense')->assertJsonPath('plain', 'The total of cells C2 to C5.');
    }

    public function test_each_value_gets_a_line(): void
    {
        $this->ask('Calculate the total expenses for each department')->assertOk()
            ->assertJsonPath('group.heading', 'Department')
            ->assertJsonPath('group.keys', ['Sales', 'Mining', 'Admin'])
            ->assertJsonPath('group.formula', '=SUMIFS($C$2:$C$5,$A$2:$A$5,{key})')
            ->assertJsonPath('heading', 'Expense');
    }

    public function test_by_and_per_mean_each_too(): void
    {
        $this->ask('average expense by status')->assertJsonPath('group.keys', ['Open', 'Closed']);
        $this->ask('highest unit price per department')->assertJsonPath('group.formula', '=MAXIFS($D$2:$D$5,$A$2:$A$5,{key})');
    }

    public function test_a_count_for_each_value_counts_rows(): void
    {
        $this->ask('how many for each department')->assertJsonPath('group.formula', '=COUNTIFS($A$2:$A$5,{key})')->assertJsonPath('heading', 'How many');
    }

    public function test_a_group_can_carry_a_condition(): void
    {
        $this->ask('total expense for each department where status is open')->assertJsonPath('group.formula', '=SUMIFS($C$2:$C$5,$A$2:$A$5,{key},$E$2:$E$5,"open")');
    }

    public function test_the_formula_it_gives_works_out_the_right_answer(): void
    {
        $formula = $this->ask('total expense for sales')->json('formula');

        $version = StructureVersion::current($this->sheet->id);
        $base = "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}";
        $this->getJson("{$base}/sheet")->assertOk();
        $answer = $this->postJson("{$base}/cells", ['version' => $version, 'edits' => [['row' => 8, 'col' => 0, 'value' => $formula]]])->assertOk()->json();

        $this->assertSame('400', collect($answer['cells'])->firstWhere('r', 8)['v']);
    }

    public function test_a_sentence_with_no_measure_asks_for_one(): void
    {
        $this->ask('expenses please')->assertJsonPath('says', 'Say what to work out, such as the total, average, highest, lowest or how many.')->assertJsonMissingPath('formula');
    }

    public function test_a_column_it_cannot_find_is_said_with_the_ones_it_can(): void
    {
        $this->ask('total salaries')->assertJsonPath('says', 'Which column? This sheet has: Department, Item, Expense, Unit price, Status.')->assertJsonMissingPath('formula');
    }

    public function test_a_sheet_with_no_table_says_so(): void
    {
        $empty = SheetTab::create(['spreadsheet_id' => $this->sheet->id, 'name' => 'Empty', 'sort_order' => 5]);

        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$empty->id}/from-words?sentence=total")->assertJsonPath('says', 'This sheet has no headings to work from. Put headings in the top row of a table first.');
    }

    public function test_quotes_in_a_value_cannot_break_out_of_the_formula(): void
    {
        $this->ask('total expense where status is a"b')->assertJsonPath('formula', '=SUMIFS($C$2:$C$5,$E$2:$E$5,"a""b")');
    }

    public function test_a_sentence_is_needed_and_anyone_who_can_read_can_ask(): void
    {
        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/from-words")->assertUnprocessable();

        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);
        $this->actingAs($reader);
        $this->ask('total expense')->assertOk();

        $this->actingAs(User::factory()->withPersonalTeam()->create());
        $this->ask('total expense')->assertForbidden();
    }

    public function test_the_longest_heading_wins_over_one_inside_it(): void
    {
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [['row' => 0, 'col' => 5, 'value' => 'Cost'], ['row' => 0, 'col' => 6, 'value' => 'Unit cost']], $this->owner->id);

        $this->ask('highest unit cost')->assertJsonPath('formula', '=MAX($G$2:$G$5)');
    }

    public function test_a_row_of_numbers_is_not_taken_for_headings(): void
    {
        $tab = SheetTab::create(['spreadsheet_id' => $this->sheet->id, 'name' => 'Numbers first', 'sort_order' => 5]);
        app(CellWriter::class)->write($this->sheet->id, $tab->id, [
            ['row' => 0, 'col' => 0, 'value' => '1'], ['row' => 0, 'col' => 1, 'value' => '2'],
            ['row' => 2, 'col' => 0, 'value' => 'Name'], ['row' => 2, 'col' => 1, 'value' => 'Hours'],
            ['row' => 3, 'col' => 0, 'value' => 'Sipho'], ['row' => 3, 'col' => 1, 'value' => '8'],
        ], $this->owner->id);

        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$tab->id}/from-words?sentence=total+hours")->assertJsonPath('formula', '=SUM($B$4:$B$4)');
    }

    public function test_too_many_different_values_point_to_a_pivot(): void
    {
        $edits = array_map(fn (int $i) => ['row' => $i + 5, 'col' => 0, 'value' => "Dept {$i}"], range(1, 120));
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, $edits, $this->owner->id);

        $this->ask('total expense for each department')->assertJsonPath('says', 'Department has more than 100 different values. A pivot table will read better.')->assertJsonMissingPath('group');
    }
}
