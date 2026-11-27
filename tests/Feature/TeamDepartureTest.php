<?php

namespace Tests\Feature;

use App\Actions\Jetstream\RemoveTeamMember;
use App\Models\AuditEvent;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EA-1 (S-01): a team's workbooks stay with the team when someone leaves it. The person who left keeps
 * no owner rights: ownership passes to the team owner, and the policy refuses owner rights to anyone
 * no longer in the workbook's team even if a handover was missed.
 */
class TeamDepartureTest extends TestCase
{
    use RefreshDatabase;

    private function teamWithMember(string $role = 'editor'): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $member = User::factory()->withPersonalTeam()->create();
        $team->users()->attach($member, ['role' => $role]);

        return [$owner, $team, $member];
    }

    private function workbook(User $owner, ?int $teamId, string $name = 'Budget'): Spreadsheet
    {
        return Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => $teamId, 'name' => $name]);
    }

    public function test_removing_a_member_hands_their_team_workbooks_to_the_team_owner(): void
    {
        [$owner, $team, $member] = $this->teamWithMember();
        $teamBook = $this->workbook($member, $team->id);
        $ownBook = $this->workbook($member, null, 'Private');

        $this->actingAs($owner);
        Livewire::test(TeamMemberManager::class, ['team' => $team])
            ->set('teamMemberIdBeingRemoved', $member->id)
            ->call('removeTeamMember');

        $this->assertSame($owner->id, $teamBook->fresh()->owner_id);
        $this->assertSame($member->id, $ownBook->fresh()->owner_id, 'a workbook outside the team stays theirs');
        $this->assertTrue(AuditEvent::where('spreadsheet_id', $teamBook->id)->where('action', 'Passed to the team owner')->exists());
    }

    public function test_a_member_who_leaves_on_their_own_also_hands_over(): void
    {
        [$owner, $team, $member] = $this->teamWithMember();
        $teamBook = $this->workbook($member, $team->id);

        $this->actingAs($member);
        Livewire::test(TeamMemberManager::class, ['team' => $team])->call('leaveTeam');

        $this->assertSame($owner->id, $teamBook->fresh()->owner_id);
    }

    public function test_the_person_who_left_can_no_longer_reach_or_control_the_workbook(): void
    {
        [$owner, $team, $member] = $this->teamWithMember();
        $teamBook = $this->workbook($member, $team->id);
        app(RemoveTeamMember::class)->remove($owner, $team, $member);

        $this->actingAs($member);
        $this->get(route('spreadsheets.show', $teamBook))->assertForbidden();
        $this->postJson(route('grid.sharing.place', $teamBook), ['team_id' => null])->assertForbidden();
        $this->postJson("/spreadsheets/{$teamBook->id}/sharing/link/new")->assertForbidden();
        $this->delete(route('spreadsheets.destroy', $teamBook))->assertForbidden();
        $this->get(route('dashboard'))->assertDontSee('Budget');
        $this->assertNotNull($teamBook->fresh());
    }

    public function test_owner_rights_need_current_membership_even_if_a_handover_was_missed(): void
    {
        [$owner, $team, $member] = $this->teamWithMember();
        $teamBook = $this->workbook($member, $team->id);
        $team->users()->detach($member); // left without the handover (as before this fix)

        $member = $member->fresh();
        foreach (['view', 'update', 'manageSharing', 'delete', 'changePlace'] as $ability) {
            $this->assertTrue($member->cannot($ability, $teamBook), $ability);
        }
        $this->assertTrue($owner->can('update', $teamBook), 'the team owner keeps the team\'s workbook');
    }

    public function test_a_current_member_keeps_owner_rights_over_what_they_made(): void
    {
        [, $team, $member] = $this->teamWithMember('viewer');
        $teamBook = $this->workbook($member, $team->id);

        foreach (['view', 'update', 'manageSharing', 'delete', 'changePlace'] as $ability) {
            $this->assertTrue($member->can($ability, $teamBook), $ability);
        }
    }

    public function test_the_release_migration_hands_over_workbooks_of_people_who_already_left(): void
    {
        [$owner, $team, $member] = $this->teamWithMember();
        $orphan = $this->workbook($member, $team->id, 'Orphan');
        $kept = $this->workbook($member, $team->id, 'Kept');
        $team->users()->detach($member);
        $other = User::factory()->withPersonalTeam()->create();
        $team->users()->attach($other, ['role' => 'editor']);
        $still = $this->workbook($other, $team->id, 'Still a member');
        $kept->forceFill(['team_id' => null])->save();

        (require database_path('migrations/2026_11_28_000001_hand_over_team_workbooks_of_people_who_left.php'))->up();

        $this->assertSame($owner->id, $orphan->fresh()->owner_id);
        $this->assertSame($member->id, $kept->fresh()->owner_id, 'a private workbook is not touched');
        $this->assertSame($other->id, $still->fresh()->owner_id, 'a current member is not touched');
    }
}
