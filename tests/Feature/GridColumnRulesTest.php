<?php

namespace Tests\Feature;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\RuleFacts;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GridColumnRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Rules']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [
            ['row' => 0, 'col' => 0, 'value' => 'Heading'], ['row' => 1, 'col' => 0, 'value' => '10'], ['row' => 2, 'col' => 0, 'value' => '30'],
            ['row' => 3, 'col' => 0, 'value' => '10'], ['row' => 5, 'col' => 0, 'value' => 'x'], ['row' => 7, 'col' => 1, 'value' => 'far'],
        ], $this->owner->id);
    }

    /** @param array<string, mixed> $rule */
    private function add(array $rule): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/rules", ['version' => StructureVersion::current($this->sheet->id), 'add' => $rule]);
    }

    private function fresh(): SheetTab
    {
        return $this->tab->fresh();
    }

    public function test_a_column_rule_is_kept_with_its_kind(): void
    {
        $this->add(['target' => 'column', 'row' => 1, 'col' => 0, 'kind' => 'scale', 'color' => '#DCEBD9'])->assertOk();

        $rule = $this->fresh()->layout['conditional_formatting_rules'][0];
        $this->assertSame('scale', $rule['kind']);
        $this->assertSame(1, $rule['row']);
        $this->assertArrayNotHasKey('operator', $rule);
    }

    public function test_only_known_kinds_are_taken_and_only_for_a_column(): void
    {
        $this->add(['target' => 'column', 'row' => 1, 'col' => 0, 'kind' => 'rainbow', 'color' => '#DCEBD9'])->assertUnprocessable();
        $this->add(['target' => 'cell', 'row' => 1, 'col' => 0, 'kind' => 'scale', 'color' => '#DCEBD9'])->assertUnprocessable();
        $this->add(['target' => 'column', 'row' => 1, 'col' => 0, 'color' => '#DCEBD9'])->assertUnprocessable();
    }

    public function test_the_old_kind_of_rule_still_works(): void
    {
        $this->add(['target' => 'column', 'row' => 1, 'col' => 0, 'operator' => '>', 'value' => '5', 'color' => '#DCEBD9'])->assertOk();

        $this->assertSame('>', $this->fresh()->layout['conditional_formatting_rules'][0]['operator']);
    }

    public function test_the_facts_read_the_column_from_the_rule_down(): void
    {
        $this->add(['target' => 'column', 'row' => 1, 'col' => 0, 'kind' => 'bars', 'color' => '#DCEBD9'])->assertOk();
        $id = $this->fresh()->layout['conditional_formatting_rules'][0]['id'];

        $facts = app(RuleFacts::class)->of($this->fresh())[$id];

        $this->assertEquals(10, $facts['min']);
        $this->assertEquals(30, $facts['max']);
        $this->assertSame(['10'], $facts['twice']);
        $this->assertSame(7, $facts['last']);
    }

    public function test_a_rule_lower_down_does_not_read_what_is_above_it(): void
    {
        $this->add(['target' => 'column', 'row' => 2, 'col' => 0, 'kind' => 'bars', 'color' => '#DCEBD9'])->assertOk();
        $id = $this->fresh()->layout['conditional_formatting_rules'][0]['id'];

        $this->assertSame([], app(RuleFacts::class)->of($this->fresh())[$id]['twice']);
    }

    public function test_formula_answers_count(): void
    {
        $base = "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}";
        $this->getJson("{$base}/sheet")->assertOk();
        $this->postJson("{$base}/cells", ['version' => 0, 'edits' => [['row' => 4, 'col' => 0, 'value' => '=100+1']]])->assertOk();
        $this->add(['target' => 'column', 'row' => 1, 'col' => 0, 'kind' => 'scale', 'color' => '#DCEBD9'])->assertOk();
        $id = $this->fresh()->layout['conditional_formatting_rules'][0]['id'];

        $this->assertEquals(101, app(RuleFacts::class)->of($this->fresh())[$id]['max']);
    }

    public function test_the_page_is_told_the_facts_on_opening_and_when_it_asks(): void
    {
        $this->add(['target' => 'column', 'row' => 1, 'col' => 0, 'kind' => 'scale', 'color' => '#DCEBD9'])->assertOk();
        $id = $this->fresh()->layout['conditional_formatting_rules'][0]['id'];
        $base = "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}";

        $this->getJson("{$base}/sheet")->assertJsonPath("ruleFacts.{$id}.max", 30);
        $this->getJson("{$base}/rule-facts")->assertOk()->assertJsonPath("facts.{$id}.min", 10);
    }

    public function test_a_stranger_gets_no_facts(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/rule-facts")->assertForbidden();
    }

    public function test_many_repeats_are_capped(): void
    {
        $edits = [];
        foreach (range(0, 600) as $i) {
            $edits[] = ['row' => 10 + $i * 2, 'col' => 2, 'value' => "v{$i}"];
            $edits[] = ['row' => 11 + $i * 2, 'col' => 2, 'value' => "v{$i}"];
        }
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, $edits, $this->owner->id);
        $this->add(['target' => 'column', 'row' => 0, 'col' => 2, 'kind' => 'duplicates', 'color' => '#DCEBD9'])->assertOk();
        $id = $this->fresh()->layout['conditional_formatting_rules'][0]['id'];

        $this->assertCount(500, app(RuleFacts::class)->of($this->fresh())[$id]['twice']);
    }
}
