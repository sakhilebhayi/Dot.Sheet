<?php

namespace Tests\Feature;

use App\Http\Controllers\SchedulerProbeController;
use App\Models\AuditEvent;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkbookActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Payroll']);
    }

    public function test_sharing_is_recorded_with_who_and_what(): void
    {
        User::factory()->withPersonalTeam()->create(['email' => 'friend@example.test']);

        $this->actingAs($this->owner)->postJson("/spreadsheets/{$this->sheet->id}/sharing/people", ['email' => 'friend@example.test', 'permission' => 'edit', 'days' => 7])->assertOk();

        $event = AuditEvent::sole();
        $this->assertSame('Shared with someone', $event->action);
        $this->assertSame('email: friend@example.test, permission: edit', $event->detail);
        $this->assertSame('Payroll', $event->spreadsheet_name);
        $this->assertSame($this->owner->id, $event->user_id);
        $this->assertSame($this->owner->currentTeam->id, $event->team_id);
        $this->assertNotNull($event->ip);
    }

    public function test_something_refused_is_not_recorded(): void
    {
        $this->actingAs($this->owner)->postJson("/spreadsheets/{$this->sheet->id}/sharing/template", ['on' => 'nonsense'])->assertStatus(422);

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_someone_with_no_access_leaves_no_record(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)->get("/spreadsheets/{$this->sheet->id}/export/csv")->assertForbidden();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_download_is_recorded(): void
    {
        $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}/export/workbook/xlsx")->assertOk();
        $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}/export/csv")->assertOk();

        $this->assertSame(['Downloaded it', 'Downloaded a sheet as CSV'], AuditEvent::orderBy('id')->pluck('action')->all());
    }

    public function test_the_record_outlives_the_workbook(): void
    {
        $id = $this->sheet->id;
        $this->actingAs($this->owner)->delete("/spreadsheets/{$id}")->assertRedirect();

        $this->assertNull(Spreadsheet::find($id));
        $event = AuditEvent::sole();
        $this->assertSame('Deleted it', $event->action);
        $this->assertSame('Payroll', $event->spreadsheet_name);
    }

    public function test_an_edit_is_not_recorded_here(): void
    {
        $tab = $this->sheet->sheetTabs()->firstOrFail();
        app(CellWriter::class)->write($this->sheet->id, $tab->id, [['row' => 0, 'col' => 0, 'value' => 'secret salary']], $this->owner->id);
        $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}/tabs/{$tab->id}/sheet")->assertOk();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_broken_record_does_not_stop_the_work(): void
    {
        DB::statement('DROP TABLE audit_events');

        $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}/export/csv")->assertOk();
    }

    public function test_the_page_shows_a_business_owner_their_team_and_nobody_else_s(): void
    {
        $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}/export/csv")->assertOk();

        $this->actingAs($this->owner)->get('/activity')->assertOk()->assertSee('Downloaded a sheet as CSV')->assertSee('Payroll');

        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger)->get('/activity')->assertOk()->assertDontSee('Payroll');
    }

    public function test_a_team_editor_sees_only_the_workbooks_they_own(): void
    {
        $team = $this->owner->currentTeam;
        $editor = User::factory()->withPersonalTeam()->create();
        $team->users()->attach($editor, ['role' => 'editor']);
        $editor->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}/export/csv")->assertOk();
        $private = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $editor->id, 'name' => 'My own']);
        $this->actingAs($editor)->get("/spreadsheets/{$private->id}/export/csv")->assertOk();

        $this->actingAs($editor->fresh())->get('/activity')->assertOk()->assertSee('My own')->assertDontSee('Payroll');
    }

    public function test_old_records_are_pruned_and_recent_ones_stay(): void
    {
        $base = ['spreadsheet_id' => 1, 'spreadsheet_name' => 'x', 'owner_id' => 1, 'user_name' => 'x', 'action' => 'x'];
        AuditEvent::create($base + ['created_at' => now()->subDays(731)]);
        $keep = AuditEvent::create($base + ['created_at' => now()->subDays(729)]);

        $this->artisan('sheet:prune-audit')->assertSuccessful();

        $this->assertSame([$keep->id], AuditEvent::pluck('id')->all());
    }

    public function test_ready_says_ok_and_names_no_host_or_secret(): void
    {
        $response = $this->getJson('/ready')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('checks.database.pending_migrations', 0);

        $this->assertStringNotContainsString((string) config('database.connections.sqlite.database'), (string) $response->getContent());
    }

    public function test_ready_fails_when_the_scheduler_has_stopped(): void
    {
        Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->subDay()->toIso8601String());

        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('status', 'fail')->assertJsonPath('checks.scheduler.ok', false);
    }

    public function test_the_restore_check_passes_on_a_whole_database(): void
    {
        $this->artisan('sheet:verify-restore', ['--connection' => config('database.default')])->assertSuccessful();
    }

    public function test_a_csv_cell_that_starts_like_a_formula_cannot_run_when_opened(): void
    {
        $tab = $this->sheet->sheetTabs()->firstOrFail();
        app(CellWriter::class)->write($this->sheet->id, $tab->id, [['row' => 0, 'col' => 0, 'value' => "'=HYPERLINK(\"http://x\")"], ['row' => 0, 'col' => 1, 'value' => "'+1+1"], ['row' => 0, 'col' => 2, 'value' => "'@SUM(1)"]], $this->owner->id);

        foreach (['csv', 'tsv'] as $format) {
            $body = (string) $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}/export/{$format}")->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/(^|[,\t"])[=+@]/m', $body, $format);
        }
    }
}
