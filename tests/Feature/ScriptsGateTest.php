<?php

namespace Tests\Feature;

use App\Livewire\ScriptsEditor;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Saved user scripts are JavaScript run in the browser of whoever clicks Run, so they are
 * behind config('sheet.scripts_enabled') (SHEET_SCRIPTS_ENABLED), off by default.
 */
class ScriptsGateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $spreadsheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->spreadsheet = Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $this->owner->id,
            'name' => 'Scripted',
        ]);
    }

    private function panel(): Testable
    {
        return Livewire::actingAs($this->owner)->test(ScriptsEditor::class, ['spreadsheet_id' => $this->spreadsheet->id]);
    }

    public function test_scripts_are_off_by_default(): void
    {
        $this->assertFalse(config('sheet.scripts_enabled'));
    }

    public function test_while_off_the_scripts_card_is_not_shown_but_macros_are(): void
    {
        $this->panel()
            ->assertDontSee('Run in Sandbox')
            ->assertDontSee('Save Script')
            ->assertSee('Macros');
    }

    public function test_while_off_the_server_refuses_to_save_or_run_a_script(): void
    {
        $this->panel()
            ->set('scriptName', 'Sneaky')
            ->set('scriptBody', 'return [];')
            ->call('saveScript')
            ->assertSet('status', 'Scripts are turned off on this server.')
            ->call('dispatchRunScript')
            ->assertNotDispatched('run-script-in-sandbox');

        $this->assertSame([], $this->spreadsheet->fresh()->settings['scripts'] ?? []);
    }

    public function test_while_off_a_script_already_stored_cannot_be_loaded_or_run(): void
    {
        $this->spreadsheet->update(['settings' => ['scripts' => [['id' => 'abc', 'name' => 'Old', 'body' => 'return [];', 'updated_at' => now()->toISOString()]]]]);

        $this->panel()
            ->call('loadScript', 'abc')
            ->assertSet('selectedScriptId', null)
            ->call('dispatchRunScript')
            ->assertNotDispatched('run-script-in-sandbox');
    }

    public function test_macros_still_work_while_scripts_are_off(): void
    {
        $this->panel()
            ->call('saveRecordedMacro', ['actions' => [['type' => 'select', 'row' => 0, 'col' => 0]]])
            ->assertSet('status', 'Macro saved.');

        $this->assertCount(1, $this->spreadsheet->fresh()->settings['macros']);
    }

    public function test_when_turned_on_scripts_can_be_saved_and_run(): void
    {
        config(['sheet.scripts_enabled' => true]);

        $this->panel()
            ->assertSee('Run in Sandbox')
            ->set('scriptName', 'Hello')
            ->set('scriptBody', "return [{ type: 'set', cell: 'A1', value: 'Hi' }];")
            ->call('saveScript')
            ->assertSet('status', 'Script saved.')
            ->call('dispatchRunScript')
            ->assertDispatched('run-script-in-sandbox');

        $this->assertSame('Hello', $this->spreadsheet->fresh()->settings['scripts'][0]['name']);
    }

    public function test_the_panel_refuses_someone_who_can_only_view(): void
    {
        $viewer = User::factory()->create();
        $this->spreadsheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        Livewire::actingAs($viewer)
            ->test(ScriptsEditor::class, ['spreadsheet_id' => $this->spreadsheet->id])
            ->assertForbidden();
    }

    public function test_the_page_tells_the_browser_about_scripts_only_when_they_are_on(): void
    {
        $page = fn () => $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('sheet-scripts-enabled', $page());

        config(['sheet.scripts_enabled' => true]);
        $this->assertStringContainsString('<meta name="sheet-scripts-enabled" content="1">', $page());
    }

    public function test_the_client_script_never_starts_the_worker_unless_the_page_says_scripts_are_on(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('sheet-scripts-enabled', $js);
        $this->assertStringContainsString('if (scriptsEnabled) {', $js);
        // The worker is created lazily, inside the enabled branch's helper, never at load time.
        $this->assertStringNotContainsString('const scriptSandboxWorker =', $js);
        $this->assertStringContainsString('export default FormulaParser', file_get_contents(resource_path('js/formula-parser.js')));
        $this->assertStringContainsString("import FormulaParser from './formula-parser';", $js);
    }
}
