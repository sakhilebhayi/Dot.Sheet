<?php

namespace Tests\Feature;

use App\Models\AiPrompt;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Livewire\Livewire;
use Tests\TestCase;

/** EA-1: the smaller security items (S-05, S-06, S-08, S-09, S-10). */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function workbook(User $owner): Spreadsheet
    {
        return Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => null, 'name' => 'Ledger']);
    }

    public function test_an_import_status_answers_only_for_the_workbook_s_own_batch(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $mine = $this->workbook($owner);
        $other = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => User::factory()->create()->id, 'team_id' => null, 'name' => 'Theirs']);
        $batch = Bus::batch([])->name("import:spreadsheet:{$other->id}")->dispatch();

        $this->actingAs($owner)->getJson(route('spreadsheets.import.batch-status', [$mine, $batch->id]))->assertNotFound();
    }

    public function test_a_viewer_sees_only_their_own_questions_and_an_editor_sees_all(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $viewer = User::factory()->withPersonalTeam()->create();
        $sheet = $this->workbook($owner);
        $sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);
        AiPrompt::create(['user_id' => $owner->id, 'spreadsheet_id' => $sheet->id, 'prompt' => 'owner question', 'response' => 'r', 'context' => [], 'created_at' => now()]);
        AiPrompt::create(['user_id' => $viewer->id, 'spreadsheet_id' => $sheet->id, 'prompt' => 'viewer question', 'response' => 'r', 'context' => [], 'created_at' => now()]);

        $url = "/spreadsheets/{$sheet->id}/asked";
        $this->actingAs($viewer)->getJson($url)->assertOk()->assertJsonCount(1, 'asked')->assertJsonPath('asked.0.question', 'viewer question');
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(2, 'asked');
    }

    public function test_an_infodot_linked_account_cannot_change_its_email_here(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->forceFill(['infodot_user_uuid' => (string) Str::uuid()])->save();
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => 'New name', 'email' => 'other@example.test'])
            ->call('updateProfileInformation')
            ->assertHasErrors(['email']);

        $this->assertNotSame('other@example.test', $user->fresh()->email);

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => 'New name', 'email' => $user->email])
            ->call('updateProfileInformation')
            ->assertHasNoErrors();
        $this->assertSame('New name', $user->fresh()->name);
    }

    public function test_only_the_application_host_is_trusted(): void
    {
        config(['app.url' => 'https://sheets.infodot.co.za']);
        $hosts = array_values(array_filter((new class(app()) extends TrustHosts
        {
            public function shouldSpecifyTrustedHosts()
            {
                return true;
            }
        })->hosts()));

        $this->assertCount(1, $hosts);
        $this->assertMatchesRegularExpression('#'.$hosts[0].'#', 'sheets.infodot.co.za');
        $this->assertDoesNotMatchRegularExpression('#'.$hosts[0].'#', 'evil.example');
        $this->assertDoesNotMatchRegularExpression('#'.$hosts[0].'#', 'sheets.infodot.co.za.evil.example');
    }
}
