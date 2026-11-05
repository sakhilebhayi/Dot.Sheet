<?php

namespace Tests\Feature;

use App\Models\IntegrationDelivery;
use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkbookApproval;
use App\Models\WorkbookFiling;
use App\Notifications\ApprovalNeeded;
use App\Notifications\MentionedInComment;
use App\Services\CellWriter;
use App\Services\Integrations\AnalyticsSnapshot;
use App\Services\Integrations\ForwardNoticesToNotify;
use App\Services\Integrations\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GridIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = '11111111-2222-3333-4444-555555555555';

    private User $owner;

    private Team $team;

    private Spreadsheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->team = $this->owner->currentTeam;
        $this->team->forceFill(['infodot_team_uuid' => self::COMPANY])->save();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->team->id, 'name' => 'Cash flow']);
        config([
            'services.notify.url' => 'https://notify.test/api/ingest/events',
            'services.notify.secret' => 'notify-test-value',
            'services.analytics.url' => 'https://analytics.test/api/ingest/snapshots',
            'services.analytics.secret' => 'analytics-test-value',
            'services.files.url' => 'https://files.test',
            'services.files.secret' => 'files-test-value',
        ]);
    }

    private function mention(?int $spreadsheetId = null): MentionedInComment
    {
        return new MentionedInComment('Sipho', $spreadsheetId ?? $this->sheet->id, 'Cash flow', 1, 1, 'The secret words of the comment');
    }

    public function test_a_notice_is_queued_for_notify_with_nothing_private_in_it(): void
    {
        $this->owner->notify($this->mention());

        $row = IntegrationDelivery::where('integration', 'notify')->sole();
        $this->assertSame('sheet.mention', $row->event);
        $this->assertSame($this->team->id, $row->team_id);
        $this->assertSame(self::COMPANY, $row->payload['company_uuid']);
        $this->assertSame('sheet', $row->payload['platform']);
        $this->assertSame(['email' => $this->owner->email], $row->payload['recipient']);
        $this->assertSame(['business_name', 'workbook_name', 'title', 'url'], array_keys($row->payload['data']));
        $this->assertStringStartsWith('https://', $row->payload['data']['url']);
        $this->assertStringNotContainsString('secret words', json_encode($row->payload));
    }

    public function test_someone_is_never_told_twice(): void
    {
        $this->assertSame(['database'], $this->mention()->via($this->owner));

        $approval = new ApprovalNeeded($this->sheet, $this->owner);
        $this->assertSame(['database'], $approval->via($this->owner));
    }

    public function test_mail_carries_on_where_notify_cannot_reach(): void
    {
        $forward = app(ForwardNoticesToNotify::class);

        $this->team->forceFill(['infodot_team_uuid' => null])->save();
        $this->assertSame(['database', 'mail'], $forward->channelsFor($this->sheet->id));

        $this->team->forceFill(['infodot_team_uuid' => self::COMPANY])->save();
        config(['services.notify.secret' => null]);
        $this->assertSame(['database', 'mail'], $forward->channelsFor($this->sheet->id));

        config(['services.notify.secret' => 'notify-test-value']);
        $this->sheet->update(['team_id' => null]);
        $this->assertSame(['database', 'mail'], $forward->channelsFor($this->sheet->id));
    }

    public function test_nothing_is_queued_for_a_private_workbook(): void
    {
        $this->sheet->update(['team_id' => null]);

        $this->owner->notify($this->mention());

        $this->assertSame(0, IntegrationDelivery::count());
    }

    public function test_a_notice_goes_out_signed_and_is_marked_sent(): void
    {
        Http::fake(['notify.test/*' => Http::response(['ok' => true], 202)]);
        $this->owner->notify($this->mention());

        $this->assertSame(['sent' => 1, 'retrying' => 0, 'given_up' => 0], app(Outbox::class)->deliver());

        Http::assertSent(function (Request $request) {
            $expected = 'sha256='.hash_hmac('sha256', $request->header('X-InfoDot-Timestamp')[0].'.'.$request->body(), 'notify-test-value');

            return $request->header('X-InfoDot-Signature')[0] === $expected;
        });
        $this->assertSame('sent', IntegrationDelivery::sole()->status);
    }

    public function test_a_server_that_is_down_is_tried_again_and_a_refusal_is_not(): void
    {
        $this->owner->notify($this->mention());
        Http::fakeSequence('notify.test/*')->push('down', 503)->push('no', 422);

        $this->assertSame(1, app(Outbox::class)->deliver()['retrying']);
        $row = IntegrationDelivery::sole();
        $this->assertSame('pending', $row->status);
        $this->assertTrue($row->next_attempt_at->isFuture());

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertSame(1, app(Outbox::class)->deliver()['given_up']);
        Carbon::setTestNow();
        $this->assertSame('Dot.Notify refused the event as invalid.', IntegrationDelivery::sole()->last_error);
    }

    public function test_a_payload_is_not_readable_in_the_table(): void
    {
        $this->owner->notify($this->mention());

        $raw = (string) \DB::table('integration_deliveries')->value('payload');

        $this->assertStringNotContainsString($this->owner->email, $raw);
    }

    public function test_the_same_event_is_queued_once(): void
    {
        $outbox = app(Outbox::class);

        $this->assertNotNull($outbox->push('notify', $this->team->id, 'e', 'same', []));
        $this->assertNull($outbox->push('notify', $this->team->id, 'e', 'same', []));
        $this->assertSame(1, IntegrationDelivery::count());
    }

    public function test_the_daily_numbers_are_counts_of_this_business_only(): void
    {
        $tab = $this->sheet->sheetTabs()->firstOrFail();
        app(CellWriter::class)->write($this->sheet->id, $tab->id, [['row' => 0, 'col' => 0, 'value' => '=1/0']], $this->owner->id);
        $this->sheet->update(['is_template' => true]);
        $waiting = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->team->id, 'name' => 'Waits']);
        WorkbookApproval::create(['spreadsheet_id' => $waiting->id, 'state' => WorkbookApproval::WAITING]);
        WorkbookApproval::create(['spreadsheet_id' => $this->sheet->id, 'state' => WorkbookApproval::WAITING]);
        WorkbookApproval::create(['spreadsheet_id' => $this->sheet->id, 'state' => WorkbookApproval::APPROVED]);
        $other = User::factory()->withPersonalTeam()->create();
        $theirs = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $other->id, 'team_id' => $other->currentTeam->id, 'name' => 'Theirs']);
        WorkbookApproval::create(['spreadsheet_id' => $theirs->id, 'state' => WorkbookApproval::APPROVED]);

        $this->assertSame([
            'workbooks' => 2, 'edited_7d' => 1, 'formula_errors' => 1, 'awaiting_approval' => 1, 'approved' => 1, 'templates' => 1,
        ], app(AnalyticsSnapshot::class)->metrics($this->team));
        $this->assertSame(0, app(AnalyticsSnapshot::class)->metrics($this->team, now()->addDays(8))['edited_7d']);
    }

    public function test_the_daily_numbers_go_once_a_day_and_only_for_a_linked_business(): void
    {
        $this->artisan('integrations:analytics-snapshots')->assertSuccessful();
        $this->artisan('integrations:analytics-snapshots')->assertSuccessful();

        $row = IntegrationDelivery::where('integration', 'analytics')->sole();
        $this->assertSame('sheet', $row->payload['platform']);
        $this->assertSame(AnalyticsSnapshot::KEYS, array_keys($row->payload['metrics']));

        $this->team->forceFill(['infodot_team_uuid' => null])->save();
        $this->assertFalse(app(AnalyticsSnapshot::class)->queue($this->team, now()->addDay()));
    }

    public function test_no_numbers_are_queued_when_analytics_is_not_set_up(): void
    {
        config(['services.analytics.secret' => null]);

        $this->artisan('integrations:analytics-snapshots')->assertSuccessful();

        $this->assertSame(0, IntegrationDelivery::count());
    }

    private function save(?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)->postJson("/spreadsheets/{$this->sheet->id}/filed");
    }

    public function test_the_first_save_files_a_real_excel_file_for_the_business(): void
    {
        Http::fake(['files.test/*' => Http::response(['uuid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'open_url' => 'https://files.test/o/aaaa'], 201)]);

        $this->save()->assertOk()->assertJsonPath('open_url', 'https://files.test/o/aaaa');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $expected = 'sha256='.hash_hmac('sha256', $request->header('X-Dot-Timestamp')[0].'.'.$request->body(), 'files-test-value');

            return str_ends_with($request->url(), '/api/platform/files/store')
                && $request->header('X-Dot-Platform')[0] === 'sheet'
                && $request->header('X-Dot-Signature')[0] === $expected
                && $body['team_uuid'] === self::COMPANY
                && $body['name'] === 'cash-flow.xlsx'
                && $body['folder'] === 'Dot.Sheets'
                && str_starts_with((string) base64_decode($body['content_b64']), 'PK');
        });
        $this->assertSame('aaaaaaaa-0000-0000-0000-000000000001', WorkbookFiling::sole()->file_uuid);
    }

    public function test_the_next_save_is_a_new_version_from_the_version_this_app_last_saw(): void
    {
        WorkbookFiling::create(['spreadsheet_id' => $this->sheet->id, 'team_id' => $this->team->id, 'file_uuid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'version' => 3, 'open_url' => 'https://files.test/o/aaaa']);
        Http::fake(['files.test/*' => Http::response(['uuid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'version' => 4], 200)]);

        $this->save()->assertOk()->assertJsonPath('version', 4)->assertJsonPath('open_url', 'https://files.test/o/aaaa');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/files/update') && json_decode($request->body(), true)['base_version'] === 3);
        $this->assertSame(4, WorkbookFiling::sole()->version);
    }

    public function test_a_newer_version_there_is_not_overwritten(): void
    {
        WorkbookFiling::create(['spreadsheet_id' => $this->sheet->id, 'team_id' => $this->team->id, 'file_uuid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'version' => 3]);
        Http::fake(['files.test/*' => Http::response(['error' => 'version_conflict', 'current_version' => 5], 409)]);

        $this->save()->assertStatus(409)->assertJsonPath('message', 'Someone saved a newer version in Dot.Files since this one. Open it there before saving over it.');
        $this->assertSame(3, WorkbookFiling::sole()->version);
    }

    public function test_a_document_gone_from_files_is_said_and_the_next_save_starts_again(): void
    {
        WorkbookFiling::create(['spreadsheet_id' => $this->sheet->id, 'team_id' => $this->team->id, 'file_uuid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'version' => 3]);
        Http::fake(['files.test/*' => Http::response(['error' => 'not_found'], 404)]);

        $this->save()->assertStatus(422)->assertJsonPath('message', 'Dot.Files no longer has this document. Save again to file it as a new one.');
        $this->assertSame(0, WorkbookFiling::count());
    }

    public function test_a_workbook_moved_to_another_team_is_filed_there_as_new(): void
    {
        WorkbookFiling::create(['spreadsheet_id' => $this->sheet->id, 'team_id' => $this->team->id, 'file_uuid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'version' => 3]);
        $next = Team::factory()->create(['user_id' => $this->owner->id, 'personal_team' => false]);
        $next->forceFill(['infodot_team_uuid' => '99999999-2222-3333-4444-555555555555'])->save();
        $this->sheet->update(['team_id' => $next->id]);
        Http::fake(['files.test/*' => Http::response(['uuid' => 'bbbbbbbb-0000-0000-0000-000000000002'], 201)]);

        $this->save()->assertOk();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/files/store'));
        $filing = WorkbookFiling::sole();
        $this->assertSame($next->id, $filing->team_id);
        $this->assertSame('bbbbbbbb-0000-0000-0000-000000000002', $filing->file_uuid);
    }

    public function test_a_link_from_files_to_somewhere_else_is_not_passed_on(): void
    {
        Http::fake(['files.test/*' => Http::response(['uuid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'open_url' => 'https://elsewhere.test/o/aaaa'], 201)]);

        $this->save()->assertOk()->assertJsonPath('open_url', null);
    }

    public function test_a_private_workbook_is_not_filed(): void
    {
        Http::fake();
        $this->sheet->update(['team_id' => null]);

        $this->save()->assertStatus(422)->assertJsonPath('message', "Move this to a team first. Dot.Files keeps a business's documents.");
        Http::assertNothingSent();
    }

    public function test_saving_says_what_is_missing(): void
    {
        Http::fake();
        config(['services.files.secret' => null]);
        $this->save()->assertStatus(422)->assertJsonPath('message', 'Dot.Files is not set up on this server yet.');

        config(['services.files.secret' => 'files-test-value']);
        $this->team->forceFill(['infodot_team_uuid' => null])->save();
        $this->save()->assertStatus(422)->assertJsonPath('message', 'This business is not linked to its InfoDot company, so Dot.Files cannot place it.');
        Http::assertNothingSent();
    }

    public function test_a_server_that_cannot_be_reached_is_said(): void
    {
        Http::fake(['files.test/*' => Http::failedConnection()]);

        $this->save()->assertStatus(422)->assertJsonPath('message', 'Dot.Files could not be reached. Try again in a moment.');
        $this->assertSame(0, WorkbookFiling::count());
    }

    public function test_a_workbook_too_large_to_export_says_so(): void
    {
        Http::fake();
        config(['sheet.export_excel_max_cells' => 1]);
        app(CellWriter::class)->write($this->sheet->id, $this->sheet->sheetTabs()->firstOrFail()->id, [['row' => 0, 'col' => 0, 'value' => 'a'], ['row' => 0, 'col' => 1, 'value' => 'b']], $this->owner->id);

        $this->save()->assertStatus(413);
        Http::assertNothingSent();
    }

    public function test_a_reader_cannot_save_to_files(): void
    {
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->save($reader)->assertForbidden();
    }

    public function test_the_page_is_for_a_business_owner_and_admins(): void
    {
        $this->actingAs($this->owner)->get('/integrations')->assertOk()->assertSee('Dot.Notify')->assertSee('is linked to its InfoDot company');

        $admin = User::factory()->withPersonalTeam()->create();
        $this->team->users()->attach($admin, ['role' => 'admin']);
        $admin->switchTeam($this->team);
        $this->actingAs($admin)->get('/integrations')->assertOk();

        $editor = User::factory()->withPersonalTeam()->create();
        $this->team->users()->attach($editor, ['role' => 'editor']);
        $editor->switchTeam($this->team);
        $this->actingAs($editor)->get('/integrations')->assertForbidden();
        $this->actingAs($editor)->get('/dashboard')->assertDontSee('Integrations');
    }

    public function test_the_page_says_what_went_wrong_and_can_send_it_again(): void
    {
        $this->owner->notify($this->mention());
        $row = IntegrationDelivery::sole();
        $row->forceFill(['status' => 'given_up', 'next_attempt_at' => null, 'last_error' => 'Dot.Notify refused the event as invalid.'])->save();

        $this->actingAs($this->owner)->get('/integrations')->assertSee('Not sent')->assertSee('Mention email')->assertSee('Dot.Notify refused the event as invalid.')->assertSee('Send again');

        $this->actingAs($this->owner)->post("/integrations/{$row->id}/again")->assertRedirect();
        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_another_business_cannot_see_or_send_again(): void
    {
        $this->owner->notify($this->mention());
        $row = IntegrationDelivery::sole();
        $row->forceFill(['status' => 'given_up'])->save();
        $other = User::factory()->withPersonalTeam()->create();

        $this->actingAs($other)->get('/integrations')->assertOk()->assertDontSee('Mention email');
        $this->actingAs($other)->post("/integrations/{$row->id}/again")->assertNotFound();
        $this->assertSame('given_up', $row->fresh()->status);
    }

    public function test_the_page_never_says_a_platform_works_when_it_is_only_set_up(): void
    {
        $this->actingAs($this->owner)->get('/integrations')->assertSee('Set up')->assertDontSee('Last sent')->assertDontSee('Connected and working');
    }
}
