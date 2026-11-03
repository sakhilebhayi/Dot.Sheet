<?php

namespace Tests\Browser;

use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The journeys the server tests cannot see: a real browser, real clicks and keys, and the page's own
 * scripts. Each test also fails on any error the page writes to the console, which is how a broken
 * Livewire update shows itself.
 */
class SpreadsheetCriticalJourneysTest extends DuskTestCase
{
    use DatabaseTruncation;

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
            // The web fonts are refused on purpose (DuskTestCase).
            ->reject(fn (array $entry) => str_contains($entry['message'] ?? '', 'fonts.g'))
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
                // Submitting navigates away while the click is still being answered: wait for the next page.
                ->keys('#password', '{enter}')
                ->waitForLocation('/dashboard')
                ->visit('/spreadsheets/create')
                ->waitFor('#name')
                ->type('#name', 'Dusk Flow Sheet')
                ->assertInputValue('#name', 'Dusk Flow Sheet')
                ->keys('#name', '{enter}')
                ->waitFor('#gridContainer', 15)
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
                ->openLivewire('/spreadsheets/'.$sheet->id)
                // Selecting cells is a round trip each time: it must leave the page working.
                ->click($this->cell(3, 2))->settle()
                ->assertPresent($this->cell(3, 2).'.ring-2')
                ->click($this->cell(0, 0))->settle()
                ->assertPresent($this->cell(0, 0).'.ring-2')
                // Double-click opens the cell that was double-clicked.
                ->doubleClick($this->cell(2, 1))->settle()
                ->assertPresent($this->cell(2, 1).' input')
                // Keystrokes, as a person types: WebDriver's clear() blurs the input, and a cell saves on blur.
                ->keys($this->cell(2, 1).' input', 'Typed in the grid', '{enter}')->settle()
                ->assertMissing($this->cell(2, 1).' input')
                ->assertSeeIn($this->cell(2, 1), 'Typed in the grid');

            $this->assertNoConsoleErrors($browser);
        });

        $this->assertSame('Typed in the grid', $this->value($sheet, 2, 1));
        $this->assertNull($this->value($sheet, 0, 0));
    }

    public function test_formulas_calculate_follow_their_inputs_and_show_their_errors(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($user);

        $this->browse(function (Browser $browser) use ($sheet) {
            $enter = function (int $row, int $col, string $text) use ($browser) {
                $browser->doubleClick($this->cell($row, $col))->settle()
                    ->keys($this->cell($row, $col).' input', $text, '{enter}')->settle();
            };

            $browser->loginAs($sheet->owner)->openLivewire('/spreadsheets/'.$sheet->id);

            $enter(0, 0, '4');
            $enter(0, 1, '=A1*10');
            $enter(0, 2, '=B1+2');
            $browser->assertSeeIn($this->cell(0, 1), '40')->assertSeeIn($this->cell(0, 2), '42');

            // Changing the input moves every formula on screen, and a formula opens as its text.
            $browser->doubleClick($this->cell(0, 0))->settle()
                ->keys($this->cell(0, 0).' input', '{end}', '{backspace}', '5', '{enter}')->settle()
                ->assertSeeIn($this->cell(0, 1), '50')
                ->assertSeeIn($this->cell(0, 2), '52')
                ->doubleClick($this->cell(0, 1))->settle()
                ->assertInputValue($this->cell(0, 1).' input', '=A1*10')
                ->keys($this->cell(0, 1).' input', '{escape}')->settle();

            // An answer of many cells fills them.
            $enter(5, 0, '=SEQUENCE(3)');
            $browser->assertSeeIn($this->cell(5, 0), '1')
                ->assertSeeIn($this->cell(6, 0), '2')
                ->assertSeeIn($this->cell(7, 0), '3');

            $enter(1, 0, '=1/0');
            $browser->assertSeeIn($this->cell(1, 0), '#DIV/0!')
                ->assertAttribute($this->cell(1, 0).' > div', 'title', 'The formula divides by zero or by an empty cell.');

            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_save_from_the_formula_bar_and_reach_the_sharing_controls(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($user);

        $this->browse(function (Browser $browser) use ($sheet) {
            $browser->loginAs($sheet->owner)
                ->openLivewire('/spreadsheets/'.$sheet->id)
                ->click($this->cell(1, 1))->settle()
                ->type('#formulaInput', '99')
                ->click('button[onclick="saveCurrentCell()"]')->settle()
                ->assertSeeIn($this->cell(1, 1), '99')
                ->assertSee('Invite by Email');

            $this->assertNoConsoleErrors($browser);
        });

        $this->assertSame('99', $this->value($sheet, 1, 1));
    }

    public function test_take_a_copy_change_the_sheet_and_restore_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($user);
        Cell::create(['spreadsheet_id' => $sheet->id, 'sheet_tab_id' => $sheet->defaultSheetTab()->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => 'original', 'updated_at' => now()]);

        $this->browse(function (Browser $browser) use ($sheet) {
            $browser->loginAs($sheet->owner)
                ->openLivewire('/spreadsheets/'.$sheet->id)
                ->assertSeeIn($this->cell(0, 0), 'original')
                ->click('button[title="Version History"]')->settle()
                ->type('input[aria-label="Snapshot label"]', 'Before the change')
                ->press('Create Snapshot')->settle()
                ->assertSee('Before the change')
                ->click('button[wire\\:click="closeModal"]')->settle()
                ->doubleClick($this->cell(0, 0))->settle()
                ->keys($this->cell(0, 0).' input', ...['{end}', ...array_fill(0, 8, '{backspace}'), 'changed', '{enter}'])->settle()
                ->assertSeeIn($this->cell(0, 0), 'changed')
                ->click('button[title="Version History"]')->settle()
                ->press('Restore')->settle()
                ->waitForTextIn($this->cell(0, 0), 'original');

            $this->assertNoConsoleErrors($browser);
        });

        $this->assertSame('original', $this->value($sheet, 0, 0));
    }
}
