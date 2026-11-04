<?php

namespace Tests\Feature;

use App\Livewire\ScriptsEditor;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SheetMacros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GridMacrosTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Macros']);
        $this->actingAs($this->owner);
    }

    private function url(string $path = ''): string
    {
        return "/spreadsheets/{$this->sheet->id}/macros".($path === '' ? '' : "/{$path}");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function edits(): array
    {
        return [['type' => 'set', 'row' => 0, 'col' => 0, 'value' => 'Hello'], ['type' => 'set', 'row' => 1, 'col' => 2, 'value' => '=1+1']];
    }

    public function test_a_macro_is_saved_and_comes_back_with_its_edits(): void
    {
        $this->postJson($this->url(), ['name' => 'Heading', 'actions' => $this->edits()])->assertOk()
            ->assertJsonPath('macros.0.name', 'Heading')
            ->assertJsonPath('macros.0.edits.1.value', '=1+1')
            ->assertJsonCount(2, 'macros.0.edits');

        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'macros');
    }

    public function test_a_macro_with_no_name_is_given_one(): void
    {
        $this->postJson($this->url(), ['name' => null, 'actions' => $this->edits()])->assertOk();

        $this->assertStringStartsWith('Macro ', $this->getJson($this->url())->json('macros.0.name'));
    }

    public function test_the_newest_comes_first_and_a_macro_can_be_deleted(): void
    {
        $this->postJson($this->url(), ['name' => 'First', 'actions' => $this->edits()])->assertOk();
        $answer = $this->postJson($this->url(), ['name' => 'Second', 'actions' => $this->edits()])->assertOk()->json();

        $this->assertSame(['Second', 'First'], array_column($answer['macros'], 'name'));

        $this->deleteJson($this->url($answer['macros'][0]['id']))->assertOk()->assertJsonCount(1, 'macros')->assertJsonPath('macros.0.name', 'First');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function junk(): array
    {
        return [
            'not an action' => ['nonsense'],
            'an action that does not exist' => [['type' => 'run', 'row' => 0, 'col' => 0, 'code' => 'fetch("/x")']],
            'a place off the sheet' => [['type' => 'set', 'row' => 1000000, 'col' => 0, 'value' => 'x']],
            'a column off the sheet' => [['type' => 'set', 'row' => 0, 'col' => 100, 'value' => 'x']],
            'a row below nought' => [['type' => 'set', 'row' => -1, 'col' => 0, 'value' => 'x']],
            'a row in words' => [['type' => 'set', 'row' => '1', 'col' => 0, 'value' => 'x']],
            'a value that is a list' => [['type' => 'set', 'row' => 0, 'col' => 0, 'value' => ['x']]],
            'a value too long for a cell' => [['type' => 'set', 'row' => 0, 'col' => 0, 'value' => str_repeat('x', 32768)]],
        ];
    }

    #[DataProvider('junk')]
    public function test_only_real_edits_are_stored(mixed $action): void
    {
        $this->postJson($this->url(), ['name' => 'Junk', 'actions' => [$action]])->assertUnprocessable()->assertJsonPath('message', 'Nothing was recorded.');

        $this->assertSame([], app(SheetMacros::class)->of($this->sheet->fresh()));
    }

    public function test_good_edits_are_kept_and_extra_fields_are_dropped(): void
    {
        $this->postJson($this->url(), ['name' => 'Mixed', 'actions' => [
            ['type' => 'set', 'row' => 0, 'col' => 0, 'value' => 'Good', 'code' => 'alert(1)'],
            ['type' => 'run'],
        ]])->assertOk();

        $this->assertSame([['type' => 'set', 'row' => 0, 'col' => 0, 'value' => 'Good']], app(SheetMacros::class)->of($this->sheet->fresh())[0]['actions']);
    }

    public function test_an_old_macro_of_any_shape_reaches_the_page_as_checked_edits_or_not_at_all(): void
    {
        $this->sheet->update(['settings' => ['macros' => [
            ['id' => 'old', 'name' => 'Old', 'actions' => [['type' => 'select', 'row' => 0, 'col' => 0], ['type' => 'set', 'row' => 2, 'col' => 2, 'value' => 'kept'], ['type' => 'evil']]],
            'not even a macro',
            ['id' => 'bare'],
        ]]]);

        $answer = $this->getJson($this->url())->assertOk()->json();

        $this->assertCount(2, $answer['macros']);
        $this->assertSame([['type' => 'set', 'row' => 2, 'col' => 2, 'value' => 'kept']], $answer['macros'][0]['edits']);
        $this->assertSame([], $answer['macros'][1]['edits']);
    }

    public function test_a_macro_has_a_limit_and_so_has_a_spreadsheet(): void
    {
        $this->postJson($this->url(), ['name' => 'Long', 'actions' => array_fill(0, SheetMacros::MAX_ACTIONS + 1, $this->edits()[0])])->assertUnprocessable();

        foreach (range(1, SheetMacros::MAX_MACROS) as $n) {
            app(SheetMacros::class)->add($this->sheet->id, "Macro {$n}", $this->edits());
        }
        $this->postJson($this->url(), ['name' => 'One more', 'actions' => $this->edits()])->assertUnprocessable();
        $this->assertCount(SheetMacros::MAX_MACROS, app(SheetMacros::class)->of($this->sheet->fresh()));
    }

    public function test_two_people_saving_at_once_keep_both(): void
    {
        $classic = Livewire::test(ScriptsEditor::class, ['spreadsheet_id' => $this->sheet->id]);

        // Another person saves after this panel loaded its list.
        app(SheetMacros::class)->add($this->sheet->id, 'Theirs', $this->edits());

        $classic->set('macroName', 'Mine')->call('saveRecordedMacro', ['actions' => $this->edits()])->assertSet('status', 'Macro saved.');

        $this->assertEqualsCanonicalizing(['Mine', 'Theirs'], array_column(app(SheetMacros::class)->of($this->sheet->fresh()), 'name'));
    }

    public function test_deleting_in_the_classic_panel_leaves_a_macro_saved_since_it_loaded(): void
    {
        app(SheetMacros::class)->add($this->sheet->id, 'Going', $this->edits());
        $classic = Livewire::test(ScriptsEditor::class, ['spreadsheet_id' => $this->sheet->id]);
        $going = app(SheetMacros::class)->of($this->sheet->fresh())[0]['id'];

        app(SheetMacros::class)->add($this->sheet->id, 'Theirs', $this->edits());
        $classic->call('deleteMacro', $going);

        $this->assertSame(['Theirs'], array_column(app(SheetMacros::class)->of($this->sheet->fresh()), 'name'));
    }

    public function test_the_classic_panel_stores_only_real_edits_too(): void
    {
        Livewire::test(ScriptsEditor::class, ['spreadsheet_id' => $this->sheet->id])
            ->call('saveRecordedMacro', ['actions' => [['type' => 'evil']]])
            ->assertSet('status', 'Nothing was recorded.');

        $this->assertSame([], app(SheetMacros::class)->of($this->sheet->fresh()));
    }

    public function test_saving_a_macro_leaves_the_rest_of_the_settings_alone(): void
    {
        $this->sheet->update(['settings' => ['scripts' => [['id' => 'kept']], 'other' => 1]]);

        $this->postJson($this->url(), ['name' => 'x', 'actions' => $this->edits()])->assertOk();

        $settings = $this->sheet->fresh()->settings;
        $this->assertSame([['id' => 'kept']], $settings['scripts']);
        $this->assertSame(1, $settings['other']);
    }

    public function test_someone_who_cannot_edit_gets_nothing(): void
    {
        app(SheetMacros::class)->add($this->sheet->id, 'Going', $this->edits());
        $id = app(SheetMacros::class)->of($this->sheet->fresh())[0]['id'];

        foreach (['view', 'comment'] as $permission) {
            $user = User::factory()->withPersonalTeam()->create();
            $this->sheet->sharedUsers()->attach($user->id, ['permission' => $permission]);
            $this->actingAs($user);

            $this->getJson($this->url())->assertForbidden();
            $this->postJson($this->url(), ['name' => 'x', 'actions' => $this->edits()])->assertForbidden();
            $this->deleteJson($this->url($id))->assertForbidden();
            $this->get("/spreadsheets/{$this->sheet->id}")->assertOk()->assertDontSee('id="macros"', false);
        }

        $this->actingAs(User::factory()->withPersonalTeam()->create());
        $this->getJson($this->url())->assertForbidden();
        $this->assertCount(1, app(SheetMacros::class)->of($this->sheet->fresh()));
    }

    public function test_the_new_editor_has_no_way_to_run_written_code(): void
    {
        $sources = '';
        foreach (glob(resource_path('js/grid/*.js')) as $file) {
            $sources .= file_get_contents($file);
        }

        foreach (['new Worker', 'eval(', 'new Function', 'runSpreadsheetScriptSandbox', 'run-script-in-sandbox'] as $door) {
            $this->assertStringNotContainsString($door, $sources);
        }
    }

    public function test_the_page_says_whether_scripts_are_on(): void
    {
        $this->get("/spreadsheets/{$this->sheet->id}")->assertOk()->assertViewHas('boot', fn (array $boot) => $boot['scripts'] === false);

        config(['sheet.scripts_enabled' => true]);
        $this->get("/spreadsheets/{$this->sheet->id}")->assertOk()->assertViewHas('boot', fn (array $boot) => $boot['scripts'] === true);
    }
}
