<?php

namespace Tests\Feature;

use App\Actions\Jetstream\DeleteUser;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Http\Controllers\NoticeController;
use App\Livewire\Profile\EmailPreferencesForm;
use App\Models\Cell;
use App\Models\Comment;
use App\Models\IntegrationDelivery;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Notifications\ActivityNotice;
use App\Notifications\ApprovalNeeded;
use App\Notifications\MentionedInComment;
use App\Notifications\WorkflowRuleTriggered;
use App\Services\Integrations\Outbox;
use App\Services\SheetComments;
use App\Services\SheetSharing;
use App\Services\SheetWorkflows;
use App\Services\WorkbookApprovals;
use App\Support\MailText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole;
use Livewire\Livewire;
use Tests\TestCase;

/** EA-2: email and notifications (E-02 to E-10). */
class EmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function workbook(User $owner, ?int $teamId = null, string $name = 'Budget'): Spreadsheet
    {
        return Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => $teamId, 'name' => $name]);
    }

    private function comment(Spreadsheet $sheet, User $by, string $text): Comment
    {
        return Comment::create(['spreadsheet_id' => $sheet->id, 'sheet_tab_id' => $sheet->sheetTabs()->first()->id, 'user_id' => $by->id, 'row_index' => 0, 'col_index' => 0, 'content' => $text, 'resolved' => false]);
    }

    public function test_text_people_typed_never_becomes_a_link_in_an_email(): void
    {
        $owner = User::factory()->create(['name' => '[Click](https://evil.example/a)']);
        $sheet = $this->workbook($owner, null, 'Pay at evil.example now');

        $mails = [
            new MentionedInComment($owner->name, $sheet->id, $sheet->name, 1, 1, '<https://evil.example> [here](https://evil.example)'),
            new ApprovalNeeded($sheet, $owner),
            new WorkflowRuleTriggered($sheet->id, $sheet->name, '[x](https://evil.example)', 'A1', 'evil.example'),
            new ActivityNotice('shared', 'collaboration', 'A workbook was shared with you', $owner->name.' shared "'.$sheet->name.'"'),
        ];

        foreach ($mails as $notice) {
            $html = (string) $notice->toMail($owner)->render();
            $this->assertStringNotContainsString('href="https://evil.example', $html, $notice::class);
            $this->assertStringNotContainsString('evil.example', strip_tags($html), $notice::class.': a bare domain carries a zero-width break, so mail programs do not link it');
            $this->assertStringContainsString('evil.'."\u{200B}".'example', strip_tags($html), $notice::class);
        }
        $this->assertSame('\\[a\\]\\(b\\)', MailText::inline('[a](b)'));
    }

    public function test_every_notice_is_sent_by_the_queue_after_the_change_commits(): void
    {
        foreach ([MentionedInComment::class, ApprovalNeeded::class, WorkflowRuleTriggered::class, ActivityNotice::class] as $class) {
            $this->assertTrue(is_subclass_of($class, ShouldQueue::class), $class);
        }
        $notice = new ActivityNotice('t', 'security', 'T', 'b');
        $this->assertTrue($notice->afterCommit);
        $this->assertSame(3, $notice->tries);
    }

    public function test_mentions_take_wildcards_literally_reach_each_person_once_and_are_capped(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Owner']);
        $sheet = $this->workbook($owner, $owner->currentTeam->id);
        $john = User::factory()->create(['name' => 'John Smith', 'email' => 'john@acme.test']);
        $owner->currentTeam->users()->attach($john, ['role' => 'editor']);
        foreach (range(1, 25) as $i) {
            $owner->currentTeam->users()->attach(User::factory()->create(['name' => 'Ann '.$i]), ['role' => 'editor']);
        }

        app(SheetComments::class)->notifyMentions($sheet, $this->comment($sheet, $owner, 'hi @__ and @%%'), $owner);
        Notification::assertNothingSent();

        app(SheetComments::class)->notifyMentions($sheet, $this->comment($sheet, $owner, '@jo @john @John'), $owner);
        Notification::assertSentToTimes($john, MentionedInComment::class, 1);

        app(SheetComments::class)->notifyMentions($sheet, $this->comment($sheet, $owner, '@Ann'), $owner);
        $sent = collect(Notification::sentNotifications()[User::class] ?? [])->sum(fn ($byClass) => count($byClass[MentionedInComment::class] ?? []));
        $this->assertSame(1 + SheetComments::MAX_MENTIONED, $sent, 'John once, then at most MAX_MENTIONED of the 25 Anns');
    }

    public function test_one_change_sends_one_workflow_notice_per_rule(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $sheet = $this->workbook($owner);
        $sheet->update(['settings' => ['ai_workflows' => [['id' => 'low', 'name' => 'Low stock', 'column' => 'A', 'operator' => '<', 'value' => '10', 'action' => 'email_owner', 'enabled' => true]]]]);
        $tab = $sheet->sheetTabs()->first();
        $written = [];
        foreach (range(0, 49) as $row) {
            Cell::create(['spreadsheet_id' => $sheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => $row, 'col_index' => 0, 'raw_value' => '3', 'computed_value' => '3', 'updated_at' => now()]);
            $written[] = ['row' => $row, 'col' => 0];
        }

        app(SheetWorkflows::class)->cellsWritten($sheet->fresh(), $tab->id, $written, $owner->id);

        Notification::assertSentToTimes($owner, WorkflowRuleTriggered::class, 1);
        Notification::assertSentTo($owner, WorkflowRuleTriggered::class, fn ($n) => $n->cellReference === 'A1' && $n->more === 49);
    }

    public function test_optional_email_can_be_turned_off_but_security_email_cannot(): void
    {
        $user = User::factory()->create(['notification_preferences' => ['collaboration_email' => false, 'team_email' => false]]);
        $sheet = $this->workbook($user);

        $this->assertSame(['database'], (new ActivityNotice('shared', 'collaboration', 'T', 'b'))->via($user));
        $this->assertSame(['database'], (new ActivityNotice('team_role', 'team', 'T', 'b'))->via($user));
        $this->assertSame(['database', 'mail'], (new ActivityNotice('security', 'security', 'T', 'b'))->via($user));
        $this->assertNotContains('mail', (new MentionedInComment('A', $sheet->id, 'B', 1, 1, 'x'))->via($user));
        $this->assertSame(['database'], (new WorkflowRuleTriggered($sheet->id, 'B', 'R', 'A1', 1, ['email']))->via($user), 'an email-only rule still shows in the app');
    }

    public function test_people_hear_when_access_is_given_changed_or_taken_away(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $friend = User::factory()->create(['email_verified_at' => now()]);
        $sheet = $this->workbook($owner);
        $sharing = app(SheetSharing::class);

        $sharing->share($sheet, $owner, $friend->email, 'edit', 7);
        $sharing->share($sheet, $owner, $friend->email, 'edit', 7); // again: no second notice
        $sharing->setPermission($sheet, $friend->id, 'view', $owner->id);
        $sharing->remove($sheet, $friend->id, $owner->id);

        $types = collect(Notification::sentNotifications()[User::class][$friend->id][ActivityNotice::class] ?? [])->map(fn ($s) => $s['notification']->type)->all();
        $this->assertSame(['shared', 'access_changed', 'access_removed'], $types);
    }

    public function test_the_person_who_asked_hears_the_approval_decision(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $editor = User::factory()->create(['email_verified_at' => now()]);
        $sheet = $this->workbook($owner);
        $sheet->sharedUsers()->attach($editor->id, ['permission' => 'edit']);
        $approvals = app(WorkbookApprovals::class);

        $approvals->request($sheet, $editor, null);
        $approvals->decide($sheet, $owner, false, 'Fix row 4');

        Notification::assertSentTo($editor, ActivityNotice::class, fn ($n) => $n->type === 'approval_returned' && str_contains($n->body, 'Fix row 4'));
    }

    public function test_team_changes_and_security_changes_are_told(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $member = User::factory()->withPersonalTeam()->create();
        $team->users()->attach($member, ['role' => 'editor']);

        app(UpdateTeamMemberRole::class)->update($owner, $team, $member->id, 'viewer');
        Notification::assertSentTo($member, ActivityNotice::class, fn ($n) => $n->type === 'team_role' && str_contains($n->body, 'viewer'));

        app(RemoveTeamMember::class)->remove($owner, $team, $member);
        Notification::assertSentTo($member, ActivityNotice::class, fn ($n) => $n->type === 'team_removed');

        event(new PasswordUpdatedViaController($member));
        Notification::assertSentTo($member, ActivityNotice::class, fn ($n) => $n->type === 'security' && $n->category === 'security');
    }

    public function test_a_deleted_account_gets_a_confirmation_at_its_old_address(): void
    {
        Notification::fake();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'gone@acme.test']);

        app(DeleteUser::class)->delete($user);

        Notification::assertSentTo(new AnonymousNotifiable, ActivityNotice::class, fn ($n, $channels, $notifiable) => $n->type === 'account_deleted' && array_key_exists('gone@acme.test', (array) $notifiable->routes['mail']));
    }

    public function test_the_notification_centre_lists_opens_and_clears_notices(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->create();
        $sheet = $this->workbook($user);
        $user->notify(new ActivityNotice('shared', 'collaboration', 'Shared', 'Ann shared "Budget" with you.', route('spreadsheets.show', $sheet)));
        $user->notify(new ActivityNotice('team_role', 'team', 'Role', 'Your role in Acme is now viewer.', route('dashboard')));
        $mine = $user->notifications()->latest()->first();
        $other->notify(new ActivityNotice('x', 'team', 'X', 'Not yours', route('dashboard')));

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Notifications, 2 unread')->assertSee('Ann shared');

        $this->post(route('notices.open', $other->notifications()->first()->id))->assertNotFound();
        $this->post(route('notices.open', $mine->id))->assertRedirect($mine->data['url']);
        $this->assertNotNull($mine->fresh()->read_at);

        $this->post(route('notices.read-all'))->assertRedirect();
        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_a_notice_link_never_leaves_the_app(): void
    {
        $this->assertSame(route('dashboard'), NoticeController::safeUrl('https://evil.example/x'));
        $this->assertSame(route('dashboard'), NoticeController::safeUrl('//evil.example'));
        $this->assertSame('/spreadsheets/1', NoticeController::safeUrl('/spreadsheets/1'));
    }

    public function test_when_notify_gives_up_the_person_still_gets_an_email(): void
    {
        Notification::fake();
        config(['services.notify.url' => 'https://notify.test/api/ingest/events', 'services.notify.secret' => 'notify-test-value']);
        Http::fake(['notify.test/*' => Http::response(['error' => 'unknown business'], 422)]);
        app(Outbox::class)->push('notify', null, 'sheet.mention', 'k1', [
            'recipient' => ['email' => 'pat@acme.test'],
            'data' => ['title' => 'You were mentioned in a comment', 'workbook_name' => 'Budget', 'url' => route('dashboard')],
        ]);

        $result = app(Outbox::class)->deliver();

        $this->assertSame(1, $result['given_up']);
        Notification::assertSentTo(new AnonymousNotifiable, ActivityNotice::class, fn ($n) => $n->type === 'notify_fallback' && str_contains($n->body, 'Budget'));
        $this->assertSame('given_up', IntegrationDelivery::sole()->status);
    }

    public function test_old_notices_are_pruned(): void
    {
        $user = User::factory()->create();
        $user->notify(new ActivityNotice('a', 'team', 'A', 'old read', route('dashboard')));
        $user->notify(new ActivityNotice('b', 'team', 'B', 'recent', route('dashboard')));
        $user->notifications()->where('data->message', 'old read')->update(['read_at' => now()->subDays(100), 'created_at' => now()->subDays(100)]);

        $this->artisan('notices:prune')->assertSuccessful();

        $this->assertSame(['recent'], $user->notifications()->get()->pluck('data.message')->all());
    }

    public function test_email_preferences_are_saved_from_the_profile(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(EmailPreferencesForm::class)
            ->set('email.collaboration', false)->call('save')->assertDispatched('saved');

        $this->assertFalse($user->fresh()->wantsEmailAbout('collaboration'));
        $this->assertTrue($user->fresh()->wantsEmailAbout('team'));
        $this->assertTrue($user->fresh()->wantsEmailAbout('security'));
    }
}
