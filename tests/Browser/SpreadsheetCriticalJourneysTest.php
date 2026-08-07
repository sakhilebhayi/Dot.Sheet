<?php

namespace Tests\Browser;

use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class SpreadsheetCriticalJourneysTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_user_can_login_create_and_open_spreadsheet(): void
    {
        $user = User::factory()->create([
            'email' => 'dusk@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('#email', $user->email)
                ->type('#password', 'password')
                ->press('Log in')
                ->assertPathIs('/dashboard')
                ->visit('/spreadsheets/create')
                ->type('#name', 'Dusk Flow Sheet')
                ->press('Create Spreadsheet')
                ->assertPathBeginsWith('/spreadsheets/');
        });
    }

    public function test_user_can_edit_save_and_access_sharing_controls(): void
    {
        $user = User::factory()->create();

        $spreadsheet = Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => null,
            'name' => 'Dusk Edit Sheet',
            'settings' => [],
        ]);

        $this->browse(function (Browser $browser) use ($user, $spreadsheet) {
            $browser->loginAs($user)
                ->visit('/spreadsheets/'.$spreadsheet->id)
                ->waitFor('#formulaInput')
                ->type('#formulaInput', '99')
                ->click('button[onclick="saveCurrentCell()"]')
                ->pause(700)
                ->assertSee('Invite by Email');
        });

        $this->assertDatabaseHas('cells', [
            'spreadsheet_id' => $spreadsheet->id,
            'row_index' => 0,
            'col_index' => 0,
            'raw_value' => '99',
        ]);
    }
}
