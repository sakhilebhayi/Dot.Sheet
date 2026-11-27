<?php

namespace Tests\Feature;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Exceptions\ClaimRequired;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetInvitation;
use App\Models\Team;
use App\Models\User;
use App\Services\SheetSharing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who an address belongs to, and who reaches a team's sheets. Kept from the classic editor's state tests when that
 * editor was retired (EA-6): the sharing rules are the grid's, through SheetSharing.
 */
class AccountTrustTest extends TestCase
{
    use RefreshDatabase;

    private User $person;

    private User $other;

    private Spreadsheet $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->person = User::factory()->withPersonalTeam()->create();
        $this->other = User::factory()->withPersonalTeam()->create();
        $this->theirs = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->other->id, 'team_id' => $this->other->currentTeam->id, 'name' => 'Theirs']);
    }

    public function test_a_changed_email_address_is_not_trusted_until_proven(): void
    {
        Livewire::actingAs($this->person)->test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => $this->person->name, 'email' => 'someone@elsewhere.test'])
            ->call('updateProfileInformation');

        $this->assertNull($this->person->fresh()->email_verified_at);

        // Sharing to that address grants nothing until the address is proven; an invitation waits instead.
        app(SheetSharing::class)->share($this->theirs, $this->other, 'someone@elsewhere.test', 'edit', 7);

        $this->assertFalse($this->theirs->sharedUsers()->whereKey($this->person->id)->exists());
        $this->assertTrue(SpreadsheetInvitation::where('spreadsheet_id', $this->theirs->id)->where('email', 'someone@elsewhere.test')->exists());
    }

    public function test_a_proven_address_is_shared_with_at_once(): void
    {
        $this->person->forceFill(['email_verified_at' => now()])->save();

        app(SheetSharing::class)->share($this->theirs, $this->other, strtoupper($this->person->email), 'view', 7);

        $this->assertTrue($this->theirs->sharedUsers()->whereKey($this->person->id)->exists());
    }

    /** R-54: this used to detach every membership at the first InfoDot sign-in. It now goes to the claim flow and keeps them. */
    public function test_an_infodot_sign_in_to_an_unproven_account_goes_to_the_claim_flow_and_keeps_the_teams_it_had_joined(): void
    {
        $team = Team::forceCreate(['user_id' => $this->other->id, 'name' => 'Their business', 'personal_team' => false]);
        $team->users()->attach($this->person->id, ['role' => 'editor']);
        $this->person->switchTeam($team);
        $this->person->forceFill(['email' => 'claimed@business.test', 'email_verified_at' => null])->save();

        try {
            app(ProvisionUserFromInfoDot::class)(['user' => ['id' => 9, 'uuid' => 'infodot-uuid-9', 'email' => 'claimed@business.test', 'email_verified' => true, 'name' => 'Real Owner']]);
            $this->fail('An unproven account must go to the claim flow.');
        } catch (ClaimRequired $claim) {
            $this->assertTrue($claim->user->is($this->person));
        }

        $fresh = $this->person->fresh();
        $this->assertNull($fresh->email_verified_at, 'nothing is verified until the emailed code is entered');
        $this->assertTrue($fresh->belongsToTeam($team), 'the membership is kept');
        $this->assertSame($team->id, $fresh->current_team_id);
    }

    public function test_a_team_owner_reaches_the_sheets_of_the_team(): void
    {
        $team = Team::forceCreate(['user_id' => $this->other->id, 'name' => 'Business', 'personal_team' => false]);
        $team->users()->attach($this->person->id, ['role' => 'editor']);
        $sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->person->id, 'team_id' => $team->id, 'name' => 'Team sheet']);

        $this->assertTrue($this->other->can('view', $sheet));
        $this->assertTrue($this->other->can('update', $sheet));
        $this->assertTrue($this->other->can('comment', $sheet));
        $this->assertFalse($this->other->can('delete', $sheet));
    }
}
