<?php

namespace Tests\Feature;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\StructureVersion;
use App\Services\WorkbookSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GridTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $first;

    private SheetTab $second;

    private SheetTab $third;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Tabs']);
        $this->first = $this->sheet->sheetTabs()->firstOrFail();
        $this->second = app(WorkbookSheets::class)->add($this->sheet, 'Second');
        $this->third = app(WorkbookSheets::class)->add($this->sheet, 'Third');
        $this->actingAs($this->owner);
    }

    private function url(SheetTab $tab, string $what): string
    {
        return "/spreadsheets/{$this->sheet->id}/tabs/{$tab->id}/{$what}";
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_column(app(WorkbookSheets::class)->listOf($this->sheet), 'name');
    }

    public function test_a_sheet_takes_a_colour_and_undo_puts_it_back(): void
    {
        $answer = $this->postJson($this->url($this->second, 'look/colour'), ['colour' => 'teal'])->assertOk()->json();

        $this->assertSame('teal', $this->second->fresh()->color);
        $this->assertSame(['kind' => 'colour', 'tab' => $this->second->id, 'colour' => null], $answer['undo']);
        $this->assertSame('teal', $answer['tabs'][1]['color']);

        $this->postJson($this->url($this->second, 'look/colour'), ['colour' => null])->assertOk()->assertJsonPath('undo.colour', 'teal');
        $this->assertNull($this->second->fresh()->color);
    }

    public function test_only_the_sheet_colours_are_taken(): void
    {
        $this->postJson($this->url($this->second, 'look/colour'), ['colour' => 'red'])->assertUnprocessable();
        $this->postJson($this->url($this->second, 'look/colour'), ['colour' => '#ff0000'])->assertUnprocessable();
        $this->assertNull($this->second->fresh()->color);
    }

    public function test_a_sheet_can_be_hidden_and_shown(): void
    {
        $answer = $this->postJson($this->url($this->second, 'look/hidden'), ['hidden' => true])->assertOk()->json();

        $this->assertTrue($this->second->fresh()->hidden);
        $this->assertTrue($answer['tabs'][1]['hidden']);
        $this->assertFalse($answer['undo']['hidden']);

        $this->postJson($this->url($this->second, 'look/hidden'), ['hidden' => false])->assertOk()->assertJsonPath('undo.hidden', true);
        $this->assertFalse($this->second->fresh()->hidden);
    }

    public function test_a_workbook_keeps_a_sheet_showing(): void
    {
        $this->postJson($this->url($this->second, 'look/hidden'), ['hidden' => true])->assertOk();
        $this->postJson($this->url($this->third, 'look/hidden'), ['hidden' => true])->assertOk();

        $this->postJson($this->url($this->first, 'look/hidden'), ['hidden' => true])->assertUnprocessable()->assertJsonPath('message', WorkbookSheets::LAST_SHOWING_MESSAGE);

        $this->assertFalse($this->first->fresh()->hidden);
    }

    public function test_the_last_sheet_showing_cannot_be_deleted_while_others_are_hidden(): void
    {
        $this->postJson($this->url($this->second, 'look/hidden'), ['hidden' => true])->assertOk();
        $this->postJson($this->url($this->third, 'look/hidden'), ['hidden' => true])->assertOk();

        $this->postJson($this->url($this->first, 'remove'), ['version' => StructureVersion::current($this->sheet->id)])
            ->assertUnprocessable()->assertJsonPath('message', WorkbookSheets::LAST_SHOWING_MESSAGE);

        $this->assertNotNull($this->first->fresh());
    }

    public function test_a_hidden_sheet_is_still_read_by_formulas_and_can_be_opened(): void
    {
        app(CellWriter::class)->write($this->sheet->id, $this->second->id, [['row' => 0, 'col' => 0, 'value' => '7']], $this->owner->id);
        app(CellWriter::class)->write($this->sheet->id, $this->first->id, [['row' => 0, 'col' => 0, 'value' => '=Second!A1*2']], $this->owner->id);
        $this->postJson($this->url($this->second, 'look/hidden'), ['hidden' => true])->assertOk();

        $this->getJson($this->url($this->first, 'cells').'?r1=0&r2=5')->assertOk()->assertJsonPath('cells.0.v', '14');
        $this->getJson($this->url($this->second, 'sheet'))->assertOk();
    }

    public function test_the_first_sheet_showing_is_the_one_a_public_link_opens(): void
    {
        app(CellWriter::class)->write($this->sheet->id, $this->first->id, [['row' => 0, 'col' => 0, 'value' => 'Private notes']], $this->owner->id);
        app(CellWriter::class)->write($this->sheet->id, $this->second->id, [['row' => 0, 'col' => 0, 'value' => 'Public figures']], $this->owner->id);
        $this->sheet->update(['public_enabled' => true, 'public_token' => 'tok']);

        $this->postJson($this->url($this->first, 'look/hidden'), ['hidden' => true])->assertOk();

        $this->get(route('spreadsheets.public', 'tok'))->assertOk()->assertSee('Public figures')->assertDontSee('Private notes');
    }

    public function test_sheets_take_a_new_order_and_undo_puts_it_back(): void
    {
        $answer = $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/order", ['order' => [$this->third->id, $this->first->id, $this->second->id]])->assertOk()->json();

        $this->assertSame(['Third', 'Sheet1', 'Second'], $this->names());
        $this->assertSame([$this->first->id, $this->second->id, $this->third->id], $answer['undo']['order']);

        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/order", ['order' => $answer['undo']['order']])->assertOk();
        $this->assertSame(['Sheet1', 'Second', 'Third'], $this->names());
    }

    public function test_an_order_must_name_every_sheet_of_this_workbook_once(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Other']);
        $theirs = $other->sheetTabs()->firstOrFail();
        $url = "/spreadsheets/{$this->sheet->id}/tabs/order";

        $this->postJson($url, ['order' => [$this->first->id, $this->second->id]])->assertUnprocessable();
        $this->postJson($url, ['order' => [$this->first->id, $this->first->id, $this->second->id]])->assertUnprocessable();
        $this->postJson($url, ['order' => [$this->first->id, $this->second->id, $theirs->id]])->assertUnprocessable();

        $this->assertSame(['Sheet1', 'Second', 'Third'], $this->names());
    }

    public function test_a_sheet_is_reached_only_through_its_own_workbook(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Other']);
        $theirs = $other->sheetTabs()->firstOrFail();

        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$theirs->id}/look/colour", ['colour' => 'teal'])->assertNotFound();
        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$theirs->id}/look/hidden", ['hidden' => true])->assertNotFound();
    }

    public function test_only_people_who_can_edit_change_how_sheets_are_kept(): void
    {
        foreach (['view', 'comment'] as $permission) {
            $user = User::factory()->withPersonalTeam()->create();
            $this->sheet->sharedUsers()->attach($user->id, ['permission' => $permission]);
            $this->actingAs($user);

            $this->postJson($this->url($this->second, 'look/colour'), ['colour' => 'teal'])->assertForbidden();
            $this->postJson($this->url($this->second, 'look/hidden'), ['hidden' => true])->assertForbidden();
            $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/order", ['order' => [$this->third->id, $this->first->id, $this->second->id]])->assertForbidden();
        }

        $this->assertNull($this->second->fresh()->color);
        $this->assertFalse($this->second->fresh()->hidden);
    }

    public function test_the_page_is_given_what_it_needs_about_each_sheet(): void
    {
        $this->second->update(['color' => 'plum', 'hidden' => true]);

        $this->get("/spreadsheets/{$this->sheet->id}")->assertOk()
            ->assertViewHas('boot', fn (array $boot) => $boot['tabs'][1] === ['id' => $this->second->id, 'name' => 'Second', 'color' => 'plum', 'hidden' => true, 'locked' => false]);
    }
}
