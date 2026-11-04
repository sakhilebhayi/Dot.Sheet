<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellFormat;
use App\Services\CellWriter;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GridFormatTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Formats']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [['row' => 0, 'col' => 0, 'value' => 'Title']], $this->owner->id);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function format(array $patch, int $r1 = 0, int $c1 = 0, int $r2 = 0, int $c2 = 0): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/format", [
            'version' => StructureVersion::current($this->sheet->id), 'r1' => $r1, 'r2' => $r2, 'c1' => $c1, 'c2' => $c2, ...$patch,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function style(int $row = 0, int $col = 0): ?array
    {
        return Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->first()?->formatting;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function undo(array $groups): void
    {
        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/cells", ['version' => StructureVersion::current($this->sheet->id), 'edits' => $groups[0]['edits']])->assertOk();
    }

    public function test_every_format_is_kept_and_they_add_up(): void
    {
        $this->format(['italic' => true, 'underline' => true, 'strike' => true])->assertOk();
        $this->format(['font_color' => '#1E6B45', 'bg_color' => '#FBF0BF', 'font_size' => 18, 'align' => 'center'])->assertOk();

        $this->assertEquals(['italic' => true, 'underline' => true, 'strike' => true, 'font_color' => '#1E6B45', 'bg_color' => '#FBF0BF', 'font_size' => 18, 'align' => 'center'], $this->style());
    }

    public function test_a_null_takes_one_format_off_and_leaves_the_rest(): void
    {
        $this->format(['align' => 'right', 'bg_color' => '#FBF0BF']);

        $this->format(['align' => null])->assertOk();

        $this->assertSame(['bg_color' => '#FBF0BF'], $this->style());
    }

    public function test_empty_cells_can_be_coloured_and_undo_takes_them_away(): void
    {
        $undo = $this->format(['bg_color' => '#DCEBD9'], 5, 2, 6, 3)->assertOk()->json('undo');

        $this->assertSame(['bg_color' => '#DCEBD9'], $this->style(6, 3));

        $this->undo($undo);
        $this->assertNull($this->style(6, 3));
        $this->assertSame(1, Cell::where('sheet_tab_id', $this->tab->id)->count());
    }

    public function test_clearing_takes_all_formatting_and_keeps_the_contents(): void
    {
        $this->format(['italic' => true, 'bg_color' => '#FBF0BF']);

        $undo = $this->format(['clear' => true])->assertOk()->json('undo');

        $cell = Cell::where('sheet_tab_id', $this->tab->id)->firstOrFail();
        $this->assertNull($cell->formatting);
        $this->assertSame('Title', $cell->raw_value);

        $this->undo($undo);
        $this->assertSame(['italic' => true, 'bg_color' => '#FBF0BF'], $this->style());
    }

    public function test_clearing_a_cell_that_only_held_a_colour_leaves_no_cell_and_undo_brings_it_back(): void
    {
        $this->format(['bg_color' => '#FBF0BF'], 3, 3, 3, 3);

        $undo = $this->format(['clear' => true], 3, 3, 3, 3)->assertOk()->json('undo');
        $this->assertNull(Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 3)->first());

        $this->undo($undo);
        $this->assertSame(['bg_color' => '#FBF0BF'], $this->style(3, 3));
    }

    public function test_clearing_empty_cells_creates_nothing(): void
    {
        $this->format(['clear' => true], 10, 0, 12, 2)->assertOk();

        $this->assertSame(1, Cell::where('sheet_tab_id', $this->tab->id)->count());
    }

    public function test_only_real_values_reach_a_style(): void
    {
        foreach (['url(evil)', 'red', '#12', '#12345', 'expression(1)'] as $colour) {
            $this->format(['bg_color' => $colour])->assertStatus(422);
            $this->format(['font_color' => $colour])->assertStatus(422);
        }
        $this->format(['font_size' => 200])->assertStatus(422);
        $this->format(['font_size' => 'huge'])->assertStatus(422);
        $this->format(['align' => 'justify'])->assertStatus(422);
        $this->format(['italic' => 'yes please'])->assertStatus(422);

        $this->assertNull($this->style());
    }

    public function test_other_keys_in_the_request_are_not_stored(): void
    {
        $this->format(['bold' => true, 'onclick' => 'x', 'raw_value' => 'changed'])->assertOk();

        $this->assertSame(['bold' => true], $this->style());
        $this->assertSame('Title', Cell::where('sheet_tab_id', $this->tab->id)->value('raw_value'));
    }

    public function test_a_merged_cell_is_formatted_through_its_first_cell(): void
    {
        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/merge", ['version' => StructureVersion::current($this->sheet->id), 'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 2])->assertOk();

        $this->format(['align' => 'center'], 0, 0, 0, 2)->assertOk();

        $this->assertSame(['align' => 'center'], $this->style());
        $this->assertSame(1, Cell::where('sheet_tab_id', $this->tab->id)->count());
    }

    public function test_someone_who_can_only_view_cannot_format(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);
        $this->actingAs($viewer);

        $this->format(['italic' => true])->assertForbidden();
    }

    public function test_a_stale_browser_changes_nothing(): void
    {
        $this->format(['italic' => true, 'version' => 999])->assertStatus(409);

        $this->assertNull($this->style());
    }

    public function test_the_new_number_formats_are_kept(): void
    {
        foreach ([['number_format' => 'time'], ['number_format' => 'datetime'], ['number_format' => 'scientific'], ['number_format' => 'text']] as $patch) {
            $this->format($patch)->assertOk();
            $this->assertSame($patch['number_format'], Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 0)->value('formatting')['number_format']);
        }
    }

    public function test_a_currency_a_date_style_and_decimal_places_are_kept_and_undone(): void
    {
        $this->format(['number_format' => 'currency', 'currency' => 'USD', 'decimals' => 0])->assertOk();
        $this->format(['number_format' => 'date', 'date_style' => 'long'])->assertOk();

        $format = Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 0)->value('formatting');
        $this->assertSame(['number_format' => 'date', 'currency' => 'USD', 'decimals' => 0, 'date_style' => 'long'], $format);

        $this->format(['decimals' => null])->assertOk();
        $this->assertArrayNotHasKey('decimals', Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 0)->value('formatting'));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function refusedFormats(): array
    {
        return [
            'an unknown format' => [['number_format' => 'roman']],
            'an unknown currency' => [['currency' => 'BTC']],
            'an unknown date style' => [['date_style' => 'mdy']],
            'too many places' => [['decimals' => 7]],
            'negative places' => [['decimals' => -1]],
        ];
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('refusedFormats')]
    public function test_only_known_formats_are_taken(array $patch): void
    {
        $this->format($patch)->assertUnprocessable();
    }

    public function test_formats_that_arrive_with_a_paste_are_cleaned_too(): void
    {
        $clean = CellFormat::clean(['number_format' => 'time', 'currency' => 'BTC', 'date_style' => 'dmy', 'decimals' => 3, 'evil' => 'x']);
        $this->assertSame(['number_format' => 'time', 'date_style' => 'dmy', 'decimals' => 3], $clean);

        $this->assertNull(CellFormat::clean(['decimals' => 9]));
        $this->assertNull(CellFormat::clean(['date_style' => 'mdy']));
        $this->assertSame(['sparkline' => 'B2:M2'], CellFormat::clean(['sparkline' => 'B2:M2']));
        $this->assertNull(CellFormat::clean(['sparkline' => '<script>']));
        $this->assertNull(CellFormat::clean(['decimals' => '3']));
    }
}
