<?php

namespace Tests\Browser;

use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\DuskTestCase;

/**
 * The signed-in pages in a real browser: the list of sheets and its prints are drawn by the page, and
 * the settings forms depend on Livewire and Alpine running here without the old layout.
 */
class DotPagesTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function sheetFor(User $user, string $name, bool $filled = true): Spreadsheet
    {
        $sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $user->id, 'team_id' => $user->currentTeam?->id, 'name' => $name]);
        if ($filled) {
            app(CellWriter::class)->write($sheet->id, $sheet->sheetTabs()->firstOrFail()->id, [
                ['row' => 0, 'col' => 0, 'value' => 'Label'], ['row' => 1, 'col' => 0, 'value' => '100'],
                ['row' => 2, 'col' => 0, 'value' => '-40'], ['row' => 3, 'col' => 0, 'value' => '=SUM(A2:A3)'],
            ], $user->id);
        }

        return $sheet;
    }

    /** Leave the last test's page first: a request of its own could still land after signing in. */
    private function signIn(Browser $browser, User $user): Browser
    {
        $browser->driver->get('about:blank');
        $browser->driver->manage()->getLog('browser');

        return $browser->loginAs($user);
    }

    private function assertNoConsoleErrors(Browser $browser): void
    {
        $errors = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry) => ($entry['level'] ?? '') === 'SEVERE')
            // The public pages' web fonts are refused on purpose (DuskTestCase).
            ->reject(fn (array $entry) => str_contains($entry['message'] ?? '', 'fonts.g'))
            ->pluck('message')->values()->all();

        $this->assertSame([], $errors, 'The page wrote errors to the console.');
    }

    public function test_the_list_draws_prints_finds_and_sorts(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->sheetFor($user, 'Older sheet');
        $this->sheetFor($user, 'Blank one', false);

        $this->browse(function (Browser $browser) use ($user) {
            $this->signIn($browser, $user)->visit('/dashboard');
            $browser->waitUsing(10, 25, fn () => $browser->driver->executeScript("return document.querySelectorAll('.thumb circle').length >= 4"));

            // The large print: one thread for the one formula.
            $browser->waitUsing(10, 25, fn () => $browser->driver->executeScript("return document.querySelectorAll('#print path').length + document.querySelectorAll('#ledger-rows tr').length >= 2"));

            $browser->script("[...document.querySelectorAll('#ledger-rows tr a')].find(a => a.textContent === 'Older sheet').focus()");
            $browser->waitForTextIn('#stage-title', 'Older sheet');
            $browser->waitUsing(10, 25, fn () => $browser->driver->executeScript("return document.querySelectorAll('#print path').length === 1"));
            $this->assertSame(1, $browser->driver->executeScript("return document.querySelectorAll('#print .l').length"));

            $browser->type('#filter', 'blank');
            $browser->waitUsing(5, 25, fn () => $browser->driver->executeScript("return document.querySelectorAll('#ledger-rows tr:not([hidden])').length === 1"));
            $browser->assertSeeIn('#s-count', '1');

            $browser->clear('#filter')->type('#filter', 'nothing like this');
            $browser->waitFor('#ledger-none');

            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_deleting_asks_first(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($user, 'To go');

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->signIn($browser, $user)->visit('/dashboard');
            $browser->waitFor('#stage-delete')->click('#stage-delete');
            $browser->waitForTextIn('#confirm', 'To go');

            $browser->click('#confirm-keep')->pause(100);
            $this->assertNotNull($sheet->fresh());

            $browser->click('#stage-delete')->press('#confirm-form [type=submit]');
            $browser->waitForLocation('/spreadsheets');
            $this->assertNull(Spreadsheet::find($sheet->id));
        });
    }

    public function test_a_new_spreadsheet_starts_from_its_name(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $this->signIn($browser, $user)->visit('/dashboard');
            $browser->assertSee('Your first spreadsheet starts here');
            $browser->clickLink('New spreadsheet')->waitFor('#name');
            $browser->type('#name', 'Quarter close')->press('Create spreadsheet');
            $browser->waitUsing(10, 25, fn () => Spreadsheet::where('name', 'Quarter close')->exists());
        });
    }

    public function test_saving_your_details_and_opening_a_confirmation(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'First name']);

        $this->browse(function (Browser $browser) use ($user) {
            $this->signIn($browser, $user)->visit('/user/profile');
            $browser->waitFor('#name')->clear('#name')->type('#name', 'Second name');
            $browser->press('Save');
            $browser->waitUsing(10, 25, fn () => $user->fresh()->name === 'Second name');

            // A Livewire dialog opens and closes, so Alpine is running on this layout.
            $browser->press('Delete account');
            $open = "[...document.querySelectorAll('.modal-panel')].find(panel => panel.offsetParent)";
            $browser->waitUsing(5, 25, fn () => $browser->driver->executeScript("return !! {$open}"));
            $browser->script("[...{$open}.querySelectorAll('button')].find(b => b.textContent.trim() === 'Cancel').click()");
            $browser->waitUsing(5, 25, fn () => $browser->driver->executeScript("return ! {$open}"));

            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_choosing_a_role_when_adding_someone_to_a_team(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $this->signIn($browser, $user)->visit('/teams/'.$user->currentTeam->id);
            $browser->waitFor('.choices');
            $browser->script("document.querySelector('.choices button').click()");
            $browser->waitFor(".choices [aria-checked='true']");

            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_account_menu_signs_out(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $this->signIn($browser, $user)->visit('/dashboard');
            $browser->click('.who')->waitFor('#account');
            $browser->press('Sign out');
            $browser->waitForLocation('/');
            $browser->visit('/dashboard')->assertPathIs('/login');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_template_opens_as_a_working_workbook(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $this->signIn($browser, $user)->visit('/spreadsheets/create');
            $browser->assertSee('Mining')->assertSee('Equipment operating-hours report');

            $browser->press('Stock inventory register');
            $browser->waitUsing(10, 50, fn () => Spreadsheet::where('name', 'Stock inventory register')->exists() && str_contains($browser->driver->getCurrentURL(), '/spreadsheets/'));
            $browser->waitForTextIn('#tabs', 'Stock')->assertSeeIn('#tabs', 'Summary');
            $browser->waitForText('Reorder at');
        });
    }

    public function test_a_blank_spreadsheet_still_needs_a_name(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $this->signIn($browser, $user)->visit('/spreadsheets/create');
            $browser->press('Create spreadsheet')->pause(200)->assertPathIs('/spreadsheets/create');
            $this->assertSame(0, Spreadsheet::count());
        });
    }

    public function test_an_idea_finds_a_start_and_a_file_opens_as_a_workbook(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = tempnam(sys_get_temp_dir(), 'start').'.csv';
        file_put_contents($file, "Truck,Litres\nT1,40\n");

        $this->browse(function (Browser $browser) use ($user, $file) {
            $this->signIn($browser, $user)->visit('/spreadsheets/create');
            $browser->type('#idea', 'Build a stock inventory register')->press('Find a start');
            $browser->waitForText('These are the closest templates.', 10);
            $browser->assertInputValue('#idea', 'Build a stock inventory register');

            $browser->attach('file', $file)->press('Import it');
            $browser->waitUsing(10, 50, fn () => str_contains($browser->driver->getCurrentURL(), '/spreadsheets/') && ! str_contains($browser->driver->getCurrentURL(), 'create'));
            $browser->waitForText('Truck');
            $this->assertSame(1, Spreadsheet::where('owner_id', $user->id)->count());
        });

        unlink($file);
    }

    public function test_a_workbook_opens_on_a_report_of_what_came_across(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $book->getActiveSheet()->setTitle('Sales')->setCellValue('A1', 'Total')->setCellValue('B1', '=1+1');
        $book->getActiveSheet()->getCell('A2')->setValue('site')->getHyperlink()->setUrl('https://example.com');
        $book->createSheet()->setTitle('Costs')->setCellValue('A1', 'x');
        $path = tempnam(sys_get_temp_dir(), 'book').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $this->browse(function (Browser $browser) use ($user, $path) {
            $this->signIn($browser, $user)->visit('/spreadsheets/create');
            $browser->attach('file', $path)->press('Import it');
            $browser->waitFor('#report:not([hidden])', 10);
            $browser->assertSeeIn('#report-body', '2 sheets')->assertSeeIn('#report-body', 'Left behind')->assertSeeIn('#report-body', 'A link in a cell.');
            $browser->assertSeeIn('#tabs', 'Costs');

            $browser->click('#report-close')->waitUntilMissing('#report:not([hidden])');
            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'What came across', '{enter}');
            $browser->waitFor('#report:not([hidden])');
        });

        unlink($path);
    }
}
