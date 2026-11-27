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
                ->waitFor('#sheet', 15)
                ->assertPathBeginsWith('/spreadsheets/');

            $this->assertNoConsoleErrors($browser);
        });

        $this->assertDatabaseHas('spreadsheets', ['name' => 'Dusk Flow Sheet', 'owner_id' => $user->id]);
    }
}
