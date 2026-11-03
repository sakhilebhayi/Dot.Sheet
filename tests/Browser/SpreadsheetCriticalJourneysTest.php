<?php

namespace Tests\Browser;

use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The journeys the server tests cannot see: a real browser, real clicks and keys, and the page's own
 * scripts. Each test also fails on any error the page writes to the console, which is how a broken
 * Livewire update shows itself.
 */
class SpreadsheetCriticalJourneysTest extends DuskTestCase
{
    use DatabaseMigrations;

    private function sheetFor(User $user, string $name = 'Browser sheet'): Spreadsheet
    {
        return Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => $user->currentTeam?->id,
            'name' => $name,
        ]);
    }

    private function cell(int $row, int $col): string
    {
        return 'td[wire\\:key="cell-'.$row.'-'.$col.'"]';
    }

    private function value(Spreadsheet $sheet, int $row, int $col): ?string
    {
        return Cell::where('spreadsheet_id', $sheet->id)->where('row_index', $row)->where('col_index', $col)->value('raw_value');
    }

    private function assertNoConsoleErrors(Browser $browser): void
    {
        $errors = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry) => ($entry['level'] ?? '') === 'SEVERE')
            ->pluck('message')
            ->values()
            ->all();

        $this->assertSame([], $errors, 'The page wrote errors to the console.');
    }

    public function test_sign_in_and_create_a_workbook(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['email' => 'dusk@example.com']);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('#email', $user->email)
                ->type('#password', 'password')
                ->press('Sign in')
                ->assertPathIs('/dashboard')
                ->visit('/spreadsheets/create')
                ->type('#name', 'Dusk Flow Sheet')
                ->press('Create Spreadsheet')
                ->waitFor('#gridContainer')
                ->assertPathBeginsWith('/spreadsheets/');

            $this->assertNoConsoleErrors($browser);
        });

        $this->assertDatabaseHas('spreadsheets', ['name' => 'Dusk Flow Sheet', 'owner_id' => $user->id]);
    }

    public function test_edit_cells_in_the_grid_with_the_mouse_and_the_keyboard(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($user);

        $this->browse(function (Browser $browser) use ($sheet) {
            $browser->loginAs($sheet->owner)
                ->visit('/spreadsheets/'.$sheet->id)
                ->waitFor($this->cell(2, 1))
                // Selecting cells is a round trip each time: it must leave the page working.
                ->click($this->cell(3, 2))
                ->waitFor($this->cell(3, 2).'.ring-2')
                ->click($this->cell(0, 0))
                ->waitFor($this->cell(0, 0).'.ring-2')
                // Double-click opens the cell that was double-clicked.
                ->doubleClick($this->cell(2, 1))
                ->waitFor($this->cell(2, 1).' input')
                ->type($this->cell(2, 1).' input', 'Typed in the grid')
                ->keys($this->cell(2, 1).' input', '{enter}')
                ->waitUntilMissing($this->cell(2, 1).' input')
                ->waitForTextIn($this->cell(2, 1), 'Typed in the grid');

            $this->assertNoConsoleErrors($browser);
        });

        $this->assertSame('Typed in the grid', $this->value($sheet, 2, 1));
        $this->assertNull($this->value($sheet, 0, 0));
    }

    public function test_save_from_the_formula_bar_and_reach_the_sharing_controls(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($user);

        $this->browse(function (Browser $browser) use ($sheet) {
            $browser->loginAs($sheet->owner)
                ->visit('/spreadsheets/'.$sheet->id)
                ->waitFor('#formulaInput')
                ->type('#formulaInput', '99')
                ->click('button[onclick="saveCurrentCell()"]')
                ->waitForTextIn($this->cell(0, 0), '99')
                ->assertSee('Invite by Email');

            $this->assertNoConsoleErrors($browser);
        });

        $this->assertSame('99', $this->value($sheet, 0, 0));
    }

    public function test_take_a_copy_change_the_sheet_and_restore_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($user);
        Cell::create(['spreadsheet_id' => $sheet->id, 'sheet_tab_id' => $sheet->defaultSheetTab()->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => 'original', 'updated_at' => now()]);

        $this->browse(function (Browser $browser) use ($sheet) {
            $browser->loginAs($sheet->owner)
                ->visit('/spreadsheets/'.$sheet->id)
                ->waitForTextIn($this->cell(0, 0), 'original')
                ->press('🕘 History')
                ->waitFor('input[aria-label="Snapshot label"]')
                ->type('input[aria-label="Snapshot label"]', 'Before the change')
                ->press('Create Snapshot')
                ->waitForText('Before the change')
                ->click('button[wire\\:click="closeModal"]')
                ->doubleClick($this->cell(0, 0))
                ->waitFor($this->cell(0, 0).' input')
                ->clear($this->cell(0, 0).' input')
                ->type($this->cell(0, 0).' input', 'changed')
                ->keys($this->cell(0, 0).' input', '{enter}')
                ->waitForTextIn($this->cell(0, 0), 'changed')
                ->press('🕘 History')
                ->waitForText('Before the change')
                ->press('Restore')
                ->waitForTextIn($this->cell(0, 0), 'original');

            $this->assertNoConsoleErrors($browser);
        });

        $this->assertSame('original', $this->value($sheet, 0, 0));
    }
}
