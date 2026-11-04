<?php

namespace Tests\Browser;

use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetInvitation;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\PivotTableService;
use App\Services\SheetCharts;
use App\Services\SheetComments;
use App\Services\SheetMacros;
use App\Services\SheetNames;
use App\Services\SheetVersions;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use ReflectionMethod;
use Tests\DuskTestCase;

/**
 * The new editor in a real browser: the grid is drawn by the page's own scripts, so only a browser can
 * show that it works. Each test also fails on any error the page writes to the console.
 */
class DotGridEditorTest extends DuskTestCase
{
    use DatabaseTruncation;

    /**
     * @return array{0: User, 1: Spreadsheet, 2: SheetTab}
     */
    private function sheet(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $user->id, 'team_id' => $user->currentTeam?->id, 'name' => 'Cash flow']);
        $tab = $sheet->sheetTabs()->firstOrFail();

        app(CellWriter::class)->write($sheet->id, $tab->id, [
            ['row' => 0, 'col' => 0, 'value' => 'Sales'], ['row' => 0, 'col' => 1, 'value' => '1000'],
            ['row' => 1, 'col' => 0, 'value' => 'Rent'], ['row' => 1, 'col' => 1, 'value' => '-400'],
            ['row' => 2, 'col' => 0, 'value' => 'Net'], ['row' => 2, 'col' => 1, 'value' => '=SUM(B1:B2)'],
        ], $user->id);

        return [$user, $sheet, $tab];
    }

    private function open(Browser $browser, User $user, Spreadsheet $sheet): Browser
    {
        // The browser carries over between tests, and the last test's page may still have a request on
        // its way: left running, its answer overwrites the session cookie or logs an error against this
        // test. Leave that page first, then let go of what it wrote to the console.
        $browser->driver->get('about:blank');
        $browser->driver->manage()->getLog('browser');

        return $browser->loginAs($user)->visit("/spreadsheets/{$sheet->id}")
            ->waitUsing(10, 25, fn () => $browser->driver->executeScript("return [...document.querySelectorAll('.c')].some(c => c.textContent === 'Sales')"));
    }

    private function cell(Browser $browser, int $row, int $col): string
    {
        return (string) $browser->driver->executeScript(<<<'JS'
            const sizes = getComputedStyle(document.documentElement);
            const px = (name) => parseFloat(sizes.getPropertyValue(name));
            const x = px('--gutter') + (arguments[1] === 0 ? 0 : px('--label') + (arguments[1] - 1) * px('--col'));
            const at = `translate(${x}px, ${arguments[0] * px('--row')}px)`;
            return [...document.querySelectorAll('.c:not([hidden])')].find(c => c.style.transform === at)?.textContent ?? null;
        JS, [$row, $col]);
    }

    private function clickCell(Browser $browser, int $row, int $col): void
    {
        $browser->driver->executeScript(<<<'JS'
            const sizes = getComputedStyle(document.documentElement);
            const px = (name) => parseFloat(sizes.getPropertyValue(name));
            const sheet = document.getElementById('sheet').getBoundingClientRect();
            const x = sheet.left + px('--gutter') + (arguments[1] === 0 ? 20 : px('--label') + (arguments[1] - 1) * px('--col') + 20);
            const y = sheet.top + px('--head') + arguments[0] * px('--row') + 10;
            const target = document.elementFromPoint(x, y);
            target.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: x, clientY: y, button: 0, pointerType: 'mouse' }));
            window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, clientX: x, clientY: y }));
        JS, [$row, $col]);
    }

    /**
     * Fire a clipboard event. Real keys differ by platform here (Ctrl or Cmd), and these events are what
     * the page listens to.
     */
    private function clipboard(Browser $browser, string $type, ?string $text = null): string
    {
        return (string) $browser->driver->executeScript(<<<'JS'
            const data = new DataTransfer();
            if (arguments[1] !== null) data.setData('text/plain', arguments[1]);
            document.getElementById('sheet').dispatchEvent(new ClipboardEvent(arguments[0], { clipboardData: data, bubbles: true, cancelable: true }));
            return data.getData('text/plain');
        JS, [$type, $text]);
    }

    /** Drag the fill dot to a cell, with the page's own pointer events. */
    private function dragFillTo(Browser $browser, int $row, int $col): void
    {
        $browser->driver->executeScript(<<<'JS'
            const sizes = getComputedStyle(document.documentElement);
            const px = (name) => parseFloat(sizes.getPropertyValue(name));
            const sheet = document.getElementById('sheet').getBoundingClientRect();
            const handle = document.getElementById('handle');
            const from = handle.getBoundingClientRect();
            const x = sheet.left + px('--gutter') + (arguments[1] === 0 ? 20 : px('--label') + (arguments[1] - 1) * px('--col') + 20);
            const y = sheet.top + px('--head') + arguments[0] * px('--row') + 10;
            handle.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: from.left, clientY: from.top, button: 0, pointerType: 'mouse' }));
            window.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, clientX: x, clientY: y }));
            window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, clientX: x, clientY: y }));
        JS, [$row, $col]);
    }

    /** A column's right edge on screen, in the header. */
    private function columnEdge(Browser $browser, int $col): array
    {
        return $browser->driver->executeScript(<<<'JS'
            const h = [...document.querySelectorAll('.h')].find(el => el.textContent === arguments[0]);
            const box = h.getBoundingClientRect();
            return { x: box.right, y: box.top + box.height / 2 };
        JS, [chr(65 + $col)]);
    }

    private function dragColumnEdge(Browser $browser, int $col, int $by): void
    {
        $edge = $this->columnEdge($browser, $col);
        $browser->driver->executeScript(<<<'JS'
            const [x, y, by] = arguments;
            document.elementFromPoint(x - 1, y).dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: x, clientY: y, button: 0, pointerType: 'mouse' }));
            window.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, clientX: x + by, clientY: y }));
            window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, clientX: x + by, clientY: y }));
        JS, [$edge['x'], $edge['y'], $by]);
    }

    private function assertNoConsoleErrors(Browser $browser): void
    {
        $errors = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry) => ($entry['level'] ?? '') === 'SEVERE')
            ->pluck('message')->values()->all();

        $this->assertSame([], $errors, 'The page wrote errors to the console.');
    }

    public function test_the_sheet_opens_with_its_figures_in_house_style(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);

            $this->assertSame('1 000', $this->cell($browser, 0, 1));
            $this->assertSame("\u{2212}400", $this->cell($browser, 1, 1));
            $this->assertSame('600', $this->cell($browser, 2, 1));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_type_a_figure_and_the_formula_answers(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 1);

            $browser->keys('#sheet', '2000', WebDriverKeys::ENTER);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 2, 1) === '1 600');

            $this->assertSame('2000', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 1)->value('raw_value'));
            $browser->assertSeeIn('#ref', 'B2');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_keyboard_moves_edits_and_clears(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 0);

            $browser->keys('#sheet', WebDriverKeys::ARROW_DOWN, WebDriverKeys::TAB);
            $browser->assertSeeIn('#ref', 'B2');
            $browser->assertInputValue('#line', '-400');

            // Escape throws the typing away.
            $browser->keys('#sheet', '7', WebDriverKeys::ESCAPE);
            $browser->pause(100);
            $this->assertSame("\u{2212}400", $this->cell($browser, 1, 1));

            // Into the cell with F2, and the existing contents stay to be changed.
            $browser->keys('#sheet', WebDriverKeys::F2, '5', WebDriverKeys::ENTER);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 1, 1) === "\u{2212}4 005");

            $browser->keys('#sheet', WebDriverKeys::ARROW_UP, WebDriverKeys::DELETE);
            $browser->waitUsing(10, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 1)->where('col_index', 1)->doesntExist());
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_selection_adds_up_in_the_foot(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 1);

            $browser->keys('#sheet', [WebDriverKeys::SHIFT, WebDriverKeys::ARROW_DOWN], [WebDriverKeys::SHIFT, WebDriverKeys::ARROW_DOWN]);

            $browser->waitForTextIn('#s-sum', '1 200');
            $browser->assertSeeIn('#s-avg', '400')->assertSeeIn('#s-count', '3');
        });
    }

    public function test_threads_run_from_a_formula_to_what_it_reads(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 2, 1);

            $browser->waitUsing(10, 25, fn () => $browser->driver->executeScript("return document.querySelectorAll('#threads path').length === 1"));

            $this->assertSame(2, $browser->driver->executeScript("return document.querySelectorAll('.c.read').length"));
            $browser->assertInputValue('#line', '=SUM(B1:B2)');
        });
    }

    public function test_a_row_past_the_browsers_scrolling_limit_can_be_reached(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);

            $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::CONTROL, 'k']);
            $browser->waitFor('#find')->keys('#find', 'C900000', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#ref', 'C900000');

            $browser->keys('#sheet', 'far', WebDriverKeys::ENTER);
            $browser->waitUsing(10, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 899999)->exists());

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, WebDriverKeys::HOME]);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 0) === 'Sales');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_palette_starts_a_function_in_the_cell(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 3, 1);

            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'sum', WebDriverKeys::ENTER);
            $browser->waitFor('.edit')->keys('.edit', 'B1:B2)', WebDriverKeys::ENTER);

            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 3, 1) === '600');
            $this->assertSame('=SUM(B1:B2)', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 3)->where('col_index', 1)->value('formula'));
        });
    }

    public function test_someone_who_can_only_view_cannot_type(): void
    {
        [, $sheet, $tab] = $this->sheet();
        $viewer = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($viewer, $sheet, $tab) {
            $this->open($browser, $viewer, $sheet);
            $this->clickCell($browser, 0, 1);

            $browser->keys('#sheet', '9');
            $browser->pause(100);
            $browser->assertMissing('.edit');
            $this->assertSame('1000', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 1)->value('raw_value'));
            $browser->assertSeeIn('#note', 'You can read this sheet');
        });
    }

    public function test_undo_and_redo_with_the_keyboard(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 1);

            $browser->keys('#sheet', '2000', WebDriverKeys::ENTER);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 2, 1) === '1 600');

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 2, 1) === '600');
            $this->assertSame('1 000', $this->cell($browser, 0, 1));

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'y']);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 2, 1) === '1 600');

            $this->assertSame('2000', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 1)->value('raw_value'));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_copy_and_paste_a_formula_inside_the_sheet(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 2, 1);

            $this->assertSame('600', $this->clipboard($browser, 'copy'));
            $this->assertSame(1, $browser->driver->executeScript("return document.querySelectorAll('.c.copied').length"));

            $this->clickCell($browser, 2, 2);
            $this->clipboard($browser, 'paste', '600');
            $browser->waitUsing(10, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 2)->where('col_index', 2)->exists());

            $this->assertSame('=SUM(C1:C2)', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 2)->where('col_index', 2)->value('formula'));

            // Copied cells stay marked until Escape.
            $browser->keys('#sheet', WebDriverKeys::ESCAPE);
            $this->assertSame(0, $browser->driver->executeScript("return document.querySelectorAll('.c.copied').length"));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_real_copy_and_paste_keys_work(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $command = PHP_OS_FAMILY === 'Darwin' ? WebDriverKeys::META : WebDriverKeys::CONTROL;

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab, $command) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 2, 1);

            $browser->keys('#sheet', [$command, 'c']);
            $browser->waitUsing(5, 25, fn () => $browser->driver->executeScript("return document.querySelectorAll('.c.copied').length === 1"));
            $this->assertSame('sheet', $browser->driver->executeScript('return document.activeElement.id'));

            $this->clickCell($browser, 2, 2);
            $browser->keys('#sheet', [$command, 'v']);
            $browser->waitUsing(5, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 2)->where('col_index', 2)->exists());

            $this->assertSame('=SUM(C1:C2)', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 2)->where('col_index', 2)->value('formula'));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_cut_moves_cells_and_undo_brings_them_back(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 0);

            $text = $this->clipboard($browser, 'cut');
            $this->clickCell($browser, 5, 3);
            $this->clipboard($browser, 'paste', $text);

            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 5, 3) === 'Sales');
            $this->assertSame('', $this->cell($browser, 0, 0));

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 0) === 'Sales');
            $this->assertSame('', $this->cell($browser, 5, 3));
            $this->assertSame(0, Cell::where('sheet_tab_id', $tab->id)->where('row_index', 5)->count());
        });
    }

    public function test_text_from_another_program_lands_in_rows_and_columns(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 5, 0);

            $this->clipboard($browser, 'paste', "Stock\t120\r\n\"Two\nlines\"\t80\r\n");

            $browser->waitUsing(10, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 6)->where('col_index', 1)->exists());
            $this->assertSame('Stock', $this->cell($browser, 5, 0));
            $this->assertSame('120', $this->cell($browser, 5, 1));
            $this->assertSame("Two\nlines", Cell::where('sheet_tab_id', $tab->id)->where('row_index', 6)->where('col_index', 0)->value('raw_value'));
            $browser->assertSeeIn('#s-count', '4');
        });
    }

    public function test_clicking_cells_writes_them_into_a_formula(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 4, 1);

            $browser->keys('#sheet', '=');
            $browser->waitFor('.edit');
            $this->clickCell($browser, 0, 1);
            $browser->assertInputValue('#line', '=B1');

            // Clicking again changes the mind about the same reference.
            $this->clickCell($browser, 1, 1);
            $browser->assertInputValue('#line', '=B2');

            $browser->keys('.edit', '+');
            $this->clickCell($browser, 0, 1);
            $browser->assertInputValue('#line', '=B2+B1');

            $browser->keys('.edit', WebDriverKeys::ENTER);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 4, 1) === '600');
            $this->assertSame('=B2+B1', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 4)->where('col_index', 1)->value('formula'));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_dragging_the_fill_dot_carries_formulas_on_and_undo_takes_them_back(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 2, 1);
            $browser->waitFor('#handle');

            $this->dragFillTo($browser, 2, 3);
            $browser->waitUsing(10, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 2)->where('col_index', 3)->exists());

            $this->assertSame('=SUM(D1:D2)', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 2)->where('col_index', 3)->value('formula'));

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 2)->where('col_index', 3)->doesntExist());
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_double_clicking_the_fill_dot_runs_down_beside_the_data(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 2);
            $browser->keys('#sheet', 'Q1', WebDriverKeys::ENTER);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 2) === 'Q1');
            $this->clickCell($browser, 0, 2);

            $browser->waitFor('#handle');
            $browser->script("document.getElementById('handle').dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))");

            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 2, 2) === 'Q3');
            $this->assertSame('Q2', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 1)->where('col_index', 2)->value('raw_value'));
        });
    }

    public function test_the_fill_keys_work(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 2, 2);

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'r']);
            $browser->waitUsing(10, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 2)->where('col_index', 2)->exists());

            $this->assertSame('=SUM(C1:C2)', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 2)->where('col_index', 2)->value('formula'));
        });
    }

    public function test_find_steps_through_matches_and_replace_can_be_undone(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 4, 'col' => 0, 'value' => 'Rent due']], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);

            $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::CONTROL, 'f']);
            $browser->waitFor('#find-text')->type('#find-text', 'rent');
            $browser->waitForTextIn('#find-count', '2');
            $this->assertSame(2, $browser->driver->executeScript("return document.querySelectorAll('.c.found').length"));

            $browser->keys('#find-text', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#ref', 'A2');
            $browser->assertSeeIn('#find-count', '1 of 2');
            $browser->keys('#find-text', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#ref', 'A5');

            $browser->type('#replace-text', 'Lease')->click('#replace-all');
            $browser->waitForTextIn('#note', 'Replaced 2 cells');
            $this->assertSame('Lease due', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 4)->value('raw_value'));

            $browser->keys('#find-text', WebDriverKeys::ESCAPE);
            $browser->waitUntilMissing('#find-text');
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 4)->value('raw_value') === 'Rent due');

            $this->assertSame(0, $browser->driver->executeScript("return document.querySelectorAll('.c.found').length"));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_view_can_find_but_has_no_fill_dot(): void
    {
        [, $sheet] = $this->sheet();
        $viewer = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($viewer, $sheet) {
            $this->open($browser, $viewer, $sheet);
            $this->clickCell($browser, 0, 1);
            $browser->assertMissing('#handle');

            $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::CONTROL, 'f']);
            $browser->waitFor('#find-text')->type('#find-text', 'sales');
            $browser->waitForTextIn('#find-count', '1');
            $browser->assertMissing('#replace-all');
        });
    }

    public function test_dragging_a_column_edge_resizes_it_and_undo_puts_it_back(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);

            $this->dragColumnEdge($browser, 1, 60);
            $browser->waitUsing(10, 25, fn () => ($tab->fresh()->layout['col_widths'][1] ?? null) !== null);

            $width = $tab->fresh()->layout['col_widths'][1];
            $this->assertGreaterThan(150, $width);
            $this->assertSame((float) $width, (float) $browser->driver->executeScript("return [...document.querySelectorAll('.h')].find(el => el.textContent === 'B').getBoundingClientRect().width"));

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => ($tab->fresh()->layout['col_widths'][1] ?? null) === null);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_double_clicking_a_column_edge_fits_what_it_holds(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 3, 'col' => 1, 'value' => 'A rather longer heading than the others']], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $edge = $this->columnEdge($browser, 1);

            $browser->driver->executeScript(<<<'JS'
                const [x, y] = arguments;
                document.elementFromPoint(x - 1, y).dispatchEvent(new MouseEvent('dblclick', { bubbles: true, clientX: x, clientY: y }));
            JS, [$edge['x'], $edge['y']]);

            $browser->waitUsing(10, 25, fn () => ($tab->fresh()->layout['col_widths'][1] ?? null) !== null);
            $this->assertGreaterThan(200, $tab->fresh()->layout['col_widths'][1]);
        });
    }

    public function test_long_text_runs_on_until_a_cell_holds_something(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $long = 'A heading long enough to need the space of several columns of this sheet';
        app(CellWriter::class)->write($sheet->id, $tab->id, [
            ['row' => 5, 'col' => 1, 'value' => $long],
            ['row' => 6, 'col' => 1, 'value' => $long],
            ['row' => 6, 'col' => 2, 'value' => 'beside'],
        ], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet, $long) {
            $this->open($browser, $user, $sheet);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 5, 1) === $long);

            $seen = $browser->driver->executeScript(<<<'JS'
                const spans = [...document.querySelectorAll('.c')].filter(c => c.textContent === arguments[0]).sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top);
                return spans.map(c => ({ over: c.classList.contains('over'), width: c.firstChild.getBoundingClientRect().width }));
            JS, [$long]);

            $this->assertTrue($seen[0]['over']);
            $this->assertGreaterThan(300, $seen[0]['width']);
            $this->assertFalse($seen[1]['over']);
            $this->assertLessThan(110, $seen[1]['width']);
        });
    }

    private function runCommand(Browser $browser, string $name): void
    {
        $browser->click('#open-palette')->waitFor('#find')->keys('#find', $name, WebDriverKeys::ENTER);
    }

    /** Where a cell's text is on screen, relative to the top left of the sheet. */
    private function placeOf(Browser $browser, string $text): ?array
    {
        return $browser->driver->executeScript(<<<'JS'
            const sheet = document.getElementById('sheet').getBoundingClientRect();
            const cells = [...document.querySelectorAll('.c:not([hidden])')].filter(c => c.textContent === arguments[0]);
            const box = cells[0]?.getBoundingClientRect();
            return box ? { x: box.left - sheet.left, y: box.top - sheet.top } : null;
        JS, [$text]);
    }

    public function test_frozen_rows_and_columns_stay_while_the_rest_scrolls(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 0, 'col' => 3, 'value' => 'Top right'], ['row' => 40, 'col' => 3, 'value' => 'Far down']], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 1, 2);
            $this->runCommand($browser, 'Freeze above');
            $browser->waitUsing(10, 25, fn () => ($tab->fresh()->layout['frozen_cols'] ?? null) === 2);
            $this->assertSame(1, $tab->fresh()->layout['frozen_rows']);

            $sales = $this->placeOf($browser, 'Sales');
            $thousand = $this->placeOf($browser, '1 000');

            $browser->script("const s = document.getElementById('sheet'); s.scrollTop = 900; s.scrollLeft = 400");
            $browser->pause(200);

            // The corner stays, the frozen row keeps its height, and the frozen column keeps its place.
            $this->assertEquals($sales, $this->placeOf($browser, 'Sales'));
            $this->assertEquals($thousand, $this->placeOf($browser, '1 000'));
            $this->assertNull($this->placeOf($browser, 'Rent'), 'A row below the frozen band scrolls away.');
            $this->assertNotNull($this->placeOf($browser, 'Far down'));
            $this->assertNoConsoleErrors($browser);

            // It survives a reload, and undo takes it away.
            $browser->visit("/spreadsheets/{$sheet->id}");
            $browser->waitUsing(10, 25, fn () => $browser->driver->executeScript("return document.getElementById('sheet').classList.contains('freeze-rows')"));
            $this->runCommand($browser, 'Unfreeze');
            $browser->waitUsing(10, 25, fn () => ($tab->fresh()->layout['frozen_rows'] ?? null) === 0);
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => ($tab->fresh()->layout['frozen_rows'] ?? null) === 1);
        });
    }

    public function test_typing_in_a_frozen_row_works_after_scrolling(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $tab->forceFill(['layout' => ['frozen_rows' => 2, 'frozen_cols' => 1]])->save();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $browser->script("document.getElementById('sheet').scrollTop = 600");
            $browser->pause(150);

            $this->clickCell($browser, 1, 1);
            $browser->keys('#sheet', '5', WebDriverKeys::ENTER);

            $browser->waitUsing(10, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 1)->where('col_index', 1)->value('raw_value') === '5');
            $this->assertNoConsoleErrors($browser);
        });
    }

    /** The size a cell's text is drawn in, on screen. */
    private function sizeOf(Browser $browser, string $text): ?array
    {
        return $browser->driver->executeScript(<<<'JS'
            const box = [...document.querySelectorAll('.c:not([hidden])')].find(c => c.textContent === arguments[0])?.getBoundingClientRect();
            return box ? { w: box.width, h: box.height } : null;
        JS, [$text]);
    }

    public function test_merged_cells_read_as_one_and_undo_brings_back_what_they_cleared(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 0);
            $browser->keys('#sheet', [WebDriverKeys::SHIFT, WebDriverKeys::ARROW_RIGHT], [WebDriverKeys::SHIFT, WebDriverKeys::ARROW_DOWN]);

            $this->runCommand($browser, 'Merge cells');
            $browser->waitForTextIn('#note', 'Merged A1:B2. 3 other cells cleared');
            $this->assertSame('Sales', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 0)->value('raw_value'));

            $size = $this->sizeOf($browser, 'Sales');
            $this->assertGreaterThan(250, $size['w']);
            $this->assertEquals(60, $size['h']);
            $this->assertNull($this->cell($browser, 1, 0) ?: null, 'A cell inside a merge is not drawn.');

            // A click anywhere in it lands on its first cell, and typing goes there.
            $this->clickCell($browser, 1, 1);
            $browser->assertSeeIn('#ref', 'A1');
            $browser->keys('#sheet', 'Title', WebDriverKeys::ENTER);
            $browser->waitUsing(10, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 0)->value('raw_value') === 'Title');
            $browser->assertSeeIn('#ref', 'A3');

            // Arrow keys go round it.
            $browser->keys('#sheet', WebDriverKeys::ARROW_UP);
            $browser->assertSeeIn('#ref', 'A1');
            $browser->keys('#sheet', WebDriverKeys::ARROW_RIGHT);
            $browser->assertSeeIn('#ref', 'C1');
            $browser->keys('#sheet', WebDriverKeys::ARROW_LEFT);
            $browser->assertSeeIn('#ref', 'A1');

            // Undo the typing, then the merge: everything it cleared comes back.
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->sizeOf($browser, 'Sales') !== null);
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 1) === '1 000');
            $this->assertSame('Rent', $this->cell($browser, 1, 0));
            $this->assertSame([], $tab->fresh()->layout['merges']);

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'y']);
            $browser->waitUsing(10, 25, fn () => count($tab->fresh()->layout['merges'] ?? []) === 1);
            $browser->waitUsing(10, 25, fn () => $this->sizeOf($browser, 'Sales')['h'] == 60);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_merge_across_the_frozen_column_runs_on_until_the_sheet_scrolls_under_it(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $tab->forceFill(['layout' => ['merges' => [['r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 2]]]])->save();
        Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 1)->delete();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $full = $this->sizeOf($browser, 'Sales')['w'];
            $this->assertGreaterThan(300, $full);

            $browser->script("document.getElementById('sheet').scrollLeft = 300");
            $browser->waitUsing(10, 25, fn () => $this->sizeOf($browser, 'Sales')['w'] < $full);

            $browser->script("document.getElementById('sheet').scrollLeft = 0");
            $browser->waitUsing(10, 25, fn () => $this->sizeOf($browser, 'Sales')['w'] === $full);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_tall_merged_cell_still_shows_when_its_first_row_has_scrolled_off(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $tab->forceFill(['layout' => ['merges' => [['r1' => 4, 'r2' => 80, 'c1' => 1, 'c2' => 1]]]])->save();
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 4, 'col' => 1, 'value' => 'Tall']], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->script("document.getElementById('sheet').scrollTop = 1200");
            $browser->pause(200);
            $this->assertEquals(77 * 30, $this->sizeOf($browser, 'Tall')['h']);

            // A click on the part that shows selects it and leaves the sheet where it is.
            $browser->driver->executeScript(<<<'JS'
                const sheet = document.getElementById('sheet').getBoundingClientRect();
                const sizes = getComputedStyle(document.documentElement);
                const px = (name) => parseFloat(sizes.getPropertyValue(name));
                const x = sheet.left + px('--gutter') + px('--label') + 20;
                const y = sheet.top + 200;
                document.elementFromPoint(x, y).dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: x, clientY: y, button: 0, pointerType: 'mouse' }));
                window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, clientX: x, clientY: y }));
            JS);
            $browser->assertSeeIn('#ref', 'B5');
            $this->assertEquals(1200, $browser->driver->executeScript("return document.getElementById('sheet').scrollTop"));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_view_cannot_merge(): void
    {
        [, $sheet] = $this->sheet();
        $viewer = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($viewer, $sheet) {
            $this->open($browser, $viewer, $sheet);
            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'Merge cells');
            $browser->pause(100)->assertDontSeeIn('#found', 'Merge cells');
            $this->assertNoConsoleErrors($browser);
        });
    }

    /** Press a point in the headers, the way a mouse does. */
    private function pressAt(Browser $browser, string $script): void
    {
        $browser->driver->executeScript(<<<JS
            const at = (() => { {$script} })();
            document.elementFromPoint(at.x, at.y).dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: at.x, clientY: at.y, button: 0, pointerType: 'mouse' }));
            window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, clientX: at.x, clientY: at.y }));
        JS);
    }

    public function test_hidden_rows_are_stepped_over_and_the_blue_dot_brings_them_back(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 1, 0);
            $this->runCommand($browser, 'Hide rows');
            $browser->waitForTextIn('#note', 'Hid row 2');

            $this->assertSame([1], $tab->fresh()->layout['hidden_rows']);
            $this->assertNull($this->placeOf($browser, 'Rent'), 'A hidden row is not drawn.');

            // The selection left the row it hid, and the keys step over it.
            $browser->assertSeeIn('#ref', 'A3');
            $browser->keys('#sheet', WebDriverKeys::ARROW_UP);
            $browser->assertSeeIn('#ref', 'A1');
            $browser->keys('#sheet', WebDriverKeys::ARROW_DOWN);
            $browser->assertSeeIn('#ref', 'A3');

            // A formula still reads what is hidden.
            $this->assertNotNull($this->placeOf($browser, '600'));

            // The dot on the next row number brings it back, and undo hides it again.
            $this->assertTrue($browser->driver->executeScript("return [...document.querySelectorAll('.n.gap:not([hidden])')].some(n => n.textContent === '3')"));
            $this->pressAt($browser, "const n = [...document.querySelectorAll('.n:not([hidden])')].find(n => n.textContent === '3').getBoundingClientRect(); return { x: n.left + n.width / 2, y: n.top + 1 };");
            $browser->waitUsing(10, 25, fn () => $tab->fresh()->layout['hidden_rows'] === []);
            $browser->waitUsing(10, 25, fn () => $this->placeOf($browser, 'Rent') !== null);

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $tab->fresh()->layout['hidden_rows'] === [1]);
            $browser->waitUsing(10, 25, fn () => $this->placeOf($browser, 'Rent') === null);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_hidden_columns_go_and_the_palette_shows_them_again(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 1);
            $this->runCommand($browser, 'Hide columns');
            $browser->waitForTextIn('#note', 'Hid column B');
            $this->assertNull($this->placeOf($browser, '1 000'));
            $browser->assertSeeIn('#ref', 'C1');

            $browser->keys('#sheet', WebDriverKeys::ARROW_LEFT);
            $browser->assertSeeIn('#ref', 'A1');

            $this->runCommand($browser, 'Show hidden columns');
            $browser->waitForTextIn('#note', 'Showing 1 column again');
            $this->assertSame([], $tab->fresh()->layout['hidden_cols']);
            $this->assertNotNull($this->placeOf($browser, '1 000'));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_going_to_a_hidden_cell_lands_on_the_next_one_that_shows(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $tab->forceFill(['layout' => ['hidden_rows' => [3, 4]]])->save();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'A4');
            $browser->waitForTextIn('#ref', 'A6');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_looking_for_something_still_in_the_classic_editor_finds_the_way_there(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'ask ai');
            $browser->waitForTextIn('#found', 'Ask AI')->assertSeeIn('#found', 'In the classic editor');

            $browser->keys('#find', WebDriverKeys::ENTER);
            $browser->waitForLocation("/spreadsheets/{$sheet->id}/classic");
            $browser->assertSee('New editor');

            $browser->clickLink('New editor')->waitForLocation("/spreadsheets/{$sheet->id}")->waitFor('#sheet');
        });
    }

    public function test_rows_go_in_and_come_out_and_undo_takes_both_back(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $at = fn (int $row, int $col) => Cell::where('sheet_tab_id', $tab->id)->where('row_index', $row)->where('col_index', $col)->first();

        $this->browse(function (Browser $browser) use ($user, $sheet, $at) {
            $this->open($browser, $user, $sheet);

            $this->clickCell($browser, 1, 0);
            $this->runCommand($browser, 'Insert rows above');
            $browser->waitForTextIn('#note', 'Inserted 1 row before row 2');
            $this->assertSame('Rent', $at(2, 0)?->raw_value);
            $this->assertSame('=SUM(B1:B3)', $at(3, 1)?->formula);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 2, 0) === 'Rent');

            // The new row takes typing, and the answer follows it.
            $browser->assertSeeIn('#ref', 'A2');
            $browser->keys('#sheet', WebDriverKeys::ARROW_RIGHT, '100', WebDriverKeys::ENTER);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 3, 1) === '700');

            // Back through the typing, then the insert.
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 3, 1) === '600');
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 1, 0) === 'Rent');
            $this->assertSame('=SUM(B1:B2)', $at(2, 1)?->formula);

            $this->clickCell($browser, 1, 0);
            $this->runCommand($browser, 'Delete rows');
            $browser->waitForTextIn('#note', 'Deleted row 2.');
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 1, 0) === 'Net');
            $this->assertNull($this->placeOf($browser, 'Rent'));

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 1, 0) === 'Rent');
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 2, 1) === '600');

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'y']);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 1, 0) === 'Net');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_columns_go_in_beside_the_selection(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 0);
            $this->runCommand($browser, 'Insert columns right');
            $browser->waitForTextIn('#note', 'Inserted 1 column after column A');
            $this->assertSame('1000', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 2)->value('raw_value'));
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 2) === '1 000');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_sheets_can_be_added_and_renamed_from_the_tabs_and_formulas_follow(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $extra = SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Stock', 'sort_order' => 2]);
        app(CellWriter::class)->write($sheet->id, $extra->id, [['row' => 0, 'col' => 0, 'value' => '7']], $user->id);
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 4, 'col' => 0, 'value' => '=Stock!A1+1']], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab, $extra) {
            $this->open($browser, $user, $sheet);

            $browser->click('#tabs .add');
            $browser->waitForTextIn('#note', 'Added Sheet3');
            $browser->assertSeeIn('#tabs [aria-current]', 'Sheet3');

            // Back on the first sheet, rename the other one on its tab.
            $browser->script("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Sheet1').click()");
            $browser->waitUsing(10, 25, fn () => $this->placeOf($browser, 'Sales') !== null);
            $browser->script("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Stock').dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))");
            $browser->waitFor('#tabs .naming');
            $browser->keys('#tabs .naming', [WebDriverKeys::CONTROL, 'a'], 'Goods in', WebDriverKeys::ENTER);

            $browser->waitForTextIn('#note', 'Renamed to Goods in');
            $this->assertSame('Goods in', $extra->fresh()->name);
            $this->assertSame("='Goods in'!A1+1", Cell::where('sheet_tab_id', $tab->id)->where('row_index', 4)->value('formula'));
            $this->assertSame('8', $this->cell($browser, 4, 0));

            // Undo puts the name and the formula back.
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $extra->fresh()->name === 'Stock');
            $this->assertSame('=Stock!A1+1', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 4)->value('formula'));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_taken_name_is_refused_and_the_tab_keeps_its_own(): void
    {
        [$user, $sheet] = $this->sheet();
        SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Stock', 'sort_order' => 2]);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Rename this sheet');
            $browser->waitFor('#tabs .naming');
            $browser->keys('#tabs .naming', [WebDriverKeys::CONTROL, 'a'], 'stock', WebDriverKeys::ENTER);

            $browser->waitForTextIn('#note', 'Another sheet already has that name');
            $browser->assertSeeIn('#tabs [aria-current]', 'Sheet1');
        });
    }

    public function test_deleting_a_sheet_says_what_it_costs_and_keeping_it_is_the_default(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $extra = SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Stock', 'sort_order' => 2]);
        app(CellWriter::class)->write($sheet->id, $extra->id, [['row' => 0, 'col' => 0, 'value' => '7']], $user->id);
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 4, 'col' => 0, 'value' => '=Stock!A1+1']], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet, $extra) {
            $this->open($browser, $user, $sheet);
            $browser->script("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Stock').click()");
            $browser->waitUsing(10, 25, fn () => $browser->driver->executeScript("return document.querySelector('#tabs [aria-current]').textContent") === 'Stock');

            $this->runCommand($browser, 'Delete this sheet');
            $browser->waitForTextIn('#confirm-text', 'Delete Stock? It holds 1 cell. 1 formula on other sheets will show #REF!. This cannot be undone.');

            // Enter, Escape or a slip of the hand keeps it.
            $this->assertSame('Keep it', $browser->driver->executeScript('return document.activeElement.textContent'));
            $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
            $browser->waitUsing(10, 25, fn () => ! $browser->driver->executeScript("return document.getElementById('confirm').open"));
            $browser->pause(200);
            $this->assertNotNull($extra->fresh());

            $this->runCommand($browser, 'Delete this sheet');
            $browser->waitUsing(10, 25, fn () => $browser->driver->executeScript("return document.getElementById('confirm').open"));
            $browser->click('#confirm-yes');
            $browser->waitForTextIn('#note', 'Deleted Stock');
            $this->assertNull($extra->fresh());
            $browser->assertSeeIn('#tabs [aria-current]', 'Sheet1');
            $browser->waitUsing(10, 25, fn () => $this->placeOf($browser, '#REF!') !== null);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_view_gets_no_way_to_change_sheets(): void
    {
        [, $sheet] = $this->sheet();
        $viewer = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($viewer, $sheet) {
            $this->open($browser, $viewer, $sheet);
            $browser->assertMissing('#tabs .add');
            $browser->script("document.querySelector('#tabs [aria-current]').dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))");
            $browser->pause(100)->assertMissing('#tabs .naming');
            $this->assertNoConsoleErrors($browser);
        });
    }

    /** What the page has drawn for the cell holding this text. */
    private function drawnAs(Browser $browser, string $text): array
    {
        return $browser->driver->executeScript(<<<'JS'
            const cell = [...document.querySelectorAll('.c:not([hidden])')].find(c => c.textContent === arguments[0]);
            const style = getComputedStyle(cell);
            return { fill: style.backgroundColor, colour: style.color, italic: style.fontStyle, line: getComputedStyle(cell.firstChild).textDecorationLine, size: style.fontSize, justify: style.justifyContent };
        JS, [$text]);
    }

    public function test_the_tray_docks_under_the_bar_and_formats_the_selection(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $at = fn (int $row, int $col) => Cell::where('sheet_tab_id', $tab->id)->where('row_index', $row)->where('col_index', $col)->first();

        $this->browse(function (Browser $browser) use ($user, $sheet, $at) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 0);
            $top = fn () => $browser->driver->executeScript("return document.getElementById('sheet').getBoundingClientRect().top");
            $before = $top();

            $browser->click('#do-more')->waitFor('#formatbar:not([hidden])');
            $this->assertGreaterThan($before, $top(), 'The tray pushes the sheet down.');
            $this->assertSame('sheet', $browser->driver->executeScript('return document.activeElement.id'));

            $browser->click('#do-italic');
            $browser->waitUsing(10, 25, fn () => ($at(0, 0)?->formatting['italic'] ?? false) === true);
            $this->assertSame('italic', $this->drawnAs($browser, 'Sales')['italic']);
            $this->assertSame('true', $browser->attribute('#do-italic', 'aria-pressed'));

            $browser->click('#do-underline');
            $browser->waitUsing(10, 25, fn () => $this->drawnAs($browser, 'Sales')['line'] === 'underline');

            $browser->click('#do-center');
            $browser->waitUsing(10, 25, fn () => $this->drawnAs($browser, 'Sales')['justify'] === 'center');

            $browser->click('#do-larger');
            $browser->waitUsing(10, 25, fn () => $this->drawnAs($browser, 'Sales')['size'] === '16px');
            $browser->assertSeeIn('#size-now', '16');

            $browser->click('#text-swatches [aria-label="Forest"]');
            $browser->waitUsing(10, 25, fn () => $this->drawnAs($browser, 'Sales')['colour'] === 'rgb(30, 107, 69)');
            $browser->click('#fill-swatches [aria-label="Butter"]');
            $browser->waitUsing(10, 25, fn () => $this->drawnAs($browser, 'Sales')['fill'] === 'rgb(251, 240, 191)');
            $this->assertSame('true', $browser->attribute('#fill-swatches [aria-label="Butter"]', 'aria-pressed'));

            // The tray follows the selection: the next cell has none of this.
            $browser->keys('#sheet', WebDriverKeys::ARROW_DOWN);
            $browser->waitUsing(10, 25, fn () => $browser->attribute('#do-italic', 'aria-pressed') === 'false');
            $browser->assertSeeIn('#size-now', '14');
            $this->assertSame('true', $browser->attribute('#fill-swatches .none', 'aria-pressed'));

            // Clear takes it all off, the words stay, and undo puts it back.
            $browser->keys('#sheet', WebDriverKeys::ARROW_UP);
            $browser->click('#do-clear');
            $browser->waitUsing(10, 25, fn () => $at(0, 0)?->formatting === null);
            $this->assertSame('Sales', $at(0, 0)->raw_value);

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => ($at(0, 0)?->formatting['bg_color'] ?? null) === '#FBF0BF');

            $browser->click('#format-close');
            $browser->waitUsing(10, 25, fn () => $top() === $before);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_cells_past_the_data_take_a_colour_and_a_selection_shows_on_filled_cells(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 8, 2);
            $browser->keys('#sheet', [WebDriverKeys::SHIFT, WebDriverKeys::ARROW_DOWN]);

            $this->runCommand($browser, 'Fill: Sage');
            $browser->waitUsing(10, 25, fn () => Cell::where('sheet_tab_id', $tab->id)->where('row_index', 9)->where('col_index', 2)->value('formatting') !== null);

            $marked = fn () => $browser->driver->executeScript(<<<'JS'
                const cell = document.querySelector('.c.filled.sel:not([hidden])');
                return cell ? getComputedStyle(cell, '::after').content : null;
            JS);
            $this->assertSame('""', $marked());
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_format_keys_work(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 0);
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'i']);
            $browser->waitUsing(10, 25, fn () => (Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 0)->first()?->formatting['italic'] ?? false) === true);
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'u']);
            $browser->waitUsing(10, 25, fn () => (Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 0)->first()?->formatting['underline'] ?? false) === true);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_merged_title_can_be_centred(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $tab->forceFill(['layout' => ['merges' => [['r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 2]]]])->save();
        Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 1)->delete();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 0);
            $this->runCommand($browser, 'Align centre');
            $browser->waitUsing(10, 25, fn () => $this->drawnAs($browser, 'Sales')['justify'] === 'center');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_tray_keeps_every_tool_on_a_phone(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->resize(500, 800);
            $this->runCommand($browser, 'Format cells');
            $browser->waitFor('#formatbar:not([hidden])');

            $hidden = fn () => $browser->driver->executeScript(<<<'JS'
                return [...document.querySelectorAll('#formatbar button')].filter(b => b.getBoundingClientRect().width === 0).map(b => b.id || b.title);
            JS);
            $this->assertSame([], $hidden());
            $this->assertFalse((bool) $browser->driver->executeScript('return document.documentElement.scrollWidth > innerWidth'));
            $browser->resize(1280, 800);
        });
    }

    public function test_someone_who_can_only_view_gets_no_format_tray(): void
    {
        [, $sheet] = $this->sheet();
        $viewer = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($viewer, $sheet) {
            $this->open($browser, $viewer, $sheet);
            $this->assertTrue((bool) $browser->driver->executeScript("return document.getElementById('do-more').disabled"));
            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'Format cells');
            $browser->pause(100)->assertDontSeeIn('#found', 'Format cells');
            $this->assertNoConsoleErrors($browser);
        });
    }

    /** The background drawn at a place on the sheet, on a sheet of default sizes. */
    private function fillAt(Browser $browser, int $row, int $col): ?string
    {
        return $browser->driver->executeScript(<<<'JS'
            const sizes = getComputedStyle(document.documentElement);
            const px = (name) => parseFloat(sizes.getPropertyValue(name));
            const x = px('--gutter') + (arguments[1] === 0 ? 0 : px('--label') + (arguments[1] - 1) * px('--col'));
            const at = `translate(${x}px, ${arguments[0] * px('--row')}px)`;
            const cell = [...document.querySelectorAll('.c:not([hidden])')].find(c => c.style.transform === at);
            return cell ? cell.style.backgroundColor : null;
        JS, [$row, $col]);
    }

    public function test_the_browser_and_the_classic_editor_agree_on_every_kind_of_rule(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $colour = '#F7E0DA';
        $drawn = 'rgb(247, 224, 218)';

        // Value in the cell, operator, what the rule compares with.
        $cases = [
            ['5', '>', '3'], ['5', '>', '5'], ['5', '>=', '5'], ['5', '<', '5.5'], ['5', '<=', '4'],
            ['5.0', '==', '5'], ['5', '==', '5'], ['5', '!=', '5.0'], ['abc', '==', 'ABC'], ['abc', '!=', 'abd'],
            ['Late fee', 'contains', 'LATE'], ['Late fee', 'contains', 'early'], ['abc', '>', '1'], ['5', '>', 'abc'],
            ['1e3', '>', '999'], ['0x10', '>', '1'], ['.5', '<', '1'], ['-3', '<', '0'], ['+4', '>=', '4'], ['=2+3', '>', '4'],
            ['=2+3', '==', '5'], ['=1/0', '==', '#DIV/0!'], ['x', 'contains', ''], ['007', '==', '7'],
        ];

        // Three short columns, so every case is on screen in any window.
        $place = fn (int $case) => [$case % 8, 1 + intdiv($case, 8)];

        $edits = [];
        $rules = [];
        foreach ($cases as $i => [$value, $operator, $expected]) {
            [$row, $col] = $place($i);
            $edits[] = ['row' => $row, 'col' => $col, 'value' => $value];
            $rules[] = ['id' => "r{$i}", 'target' => 'cell', 'row' => $row, 'col' => $col, 'operator' => $operator, 'value' => $expected, 'color' => $colour];
        }
        Cell::where('sheet_tab_id', $tab->id)->delete();
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 0, 'col' => 0, 'value' => 'Sales'], ...$edits], $user->id);
        $tab->forceFill(['layout' => ['conditional_formatting_rules' => $rules, 'frozen_cols' => 0]])->save();

        // What the classic editor decides for each cell is the answer.
        $classic = new ShowSpreadsheet;
        $classic->conditionalFormattingRules = $rules;
        $decide = new ReflectionMethod(ShowSpreadsheet::class, 'applyConditionalFormattingRules');
        $expected = [];
        foreach ($cases as $i => $case) {
            [$row, $col] = $place($i);
            $cell = Cell::where('sheet_tab_id', $tab->id)->where('row_index', $row)->where('col_index', $col)->firstOrFail();
            $expected[$i] = ($decide->invoke($classic, $row, $col, $cell->computed_value ?? $cell->raw_value ?? '', [])['bg_color'] ?? null) !== null;
        }
        $this->assertContains(true, $expected);
        $this->assertContains(false, $expected);

        $this->browse(function (Browser $browser) use ($user, $sheet, $cases, $expected, $drawn, $place) {
            $this->open($browser, $user, $sheet);
            $browser->waitUsing(10, 25, fn () => $this->fillAt($browser, ...$place(count($cases) - 1)) !== null);

            foreach ($cases as $i => [$value, $operator, $against]) {
                $this->assertSame($expected[$i], $this->fillAt($browser, ...$place($i)) === $drawn, "{$value} {$operator} {$against}");
            }
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_rule_is_set_from_a_sentence_and_follows_the_figures(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 1, 'col' => 2, 'value' => '-9']], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 0, 1);
            $this->runCommand($browser, 'Colour rules');
            $browser->waitFor('#rulebar:not([hidden])');
            $browser->assertSeeIn('#rule-list', 'This sheet has no colour rules yet');

            $browser->select('#rule-operator', '<');
            $browser->keys('#rule-value', '0', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#rule-list', 'Column B, less than 0');

            // Rent is negative, so it colours. Sales is not.
            $rose = 'rgb(247, 224, 218)';
            $browser->waitUsing(10, 25, fn () => $this->fillAt($browser, 1, 1) === $rose);
            $this->assertSame('', $this->fillAt($browser, 0, 1));
            $this->assertSame('', $this->fillAt($browser, 1, 2), 'A column rule stays in its column.');

            // A second rule shows at once, though nothing about the page has moved to redraw it.
            $browser->select('#rule-operator', '>');
            $browser->click('#rule-swatches [aria-label="Butter"]');
            $browser->keys('#rule-value', '500', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#rule-list', 'Column B, more than 500');
            $browser->waitUsing(2, 25, fn () => $this->fillAt($browser, 0, 1) === 'rgb(251, 240, 191)');
            $browser->click('#rule-list .rule:last-child button');
            $browser->waitUsing(2, 25, fn () => $this->fillAt($browser, 0, 1) === '');

            // It follows the figures.
            $this->clickCell($browser, 1, 1);
            $browser->keys('#sheet', '400', WebDriverKeys::ENTER);
            $browser->waitUsing(10, 25, fn () => $this->fillAt($browser, 1, 1) === '');

            // Taken off, then back with undo.
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->fillAt($browser, 1, 1) === $rose);
            $browser->click('#rule-list .rule button');
            $browser->waitUsing(10, 25, fn () => $this->fillAt($browser, 1, 1) === '');
            $this->assertSame([], $tab->fresh()->layout['conditional_formatting_rules']);

            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->fillAt($browser, 1, 1) === $rose);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_rule_that_needs_a_number_says_so(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Colour rules');
            $browser->waitFor('#rulebar:not([hidden])');
            $browser->keys('#rule-value', 'plenty', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#note', 'give it a number');
            $browser->assertInputValue('#rule-value', 'plenty');
        });
    }

    public function test_each_sheet_keeps_its_own_rules(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $tab->forceFill(['layout' => ['conditional_formatting_rules' => [['id' => 'a', 'target' => 'column', 'row' => 0, 'col' => 1, 'operator' => '<', 'value' => '0', 'color' => '#F7E0DA']]]])->save();
        SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Stock', 'sort_order' => 2]);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Colour rules');
            $browser->waitForTextIn('#rule-list', 'Column B, less than 0');

            $browser->script("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Stock').click()");
            $browser->waitForTextIn('#rule-list', 'no colour rules yet');
            $this->assertNoConsoleErrors($browser);
        });
    }

    /** Press a place that sits inside the sheet, the way a mouse does. */
    private function pressOn(Browser $browser, string $selector): void
    {
        $browser->driver->executeScript(<<<'JS'
            const box = document.querySelector(arguments[0]).getBoundingClientRect();
            const at = { clientX: box.left + box.width / 2, clientY: box.top + box.height / 2 };
            const target = document.elementFromPoint(at.clientX, at.clientY);
            target.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, pointerType: 'mouse', ...at }));
            window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, ...at }));
            document.querySelector(arguments[0]).click();
        JS, [$selector]);
    }

    public function test_a_list_is_set_over_a_selection_and_chosen_from(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $at = fn (int $row, int $col) => Cell::where('sheet_tab_id', $tab->id)->where('row_index', $row)->where('col_index', $col)->first();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab, $at) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 4, 2);
            $browser->keys('#sheet', [WebDriverKeys::SHIFT, WebDriverKeys::ARROW_DOWN]);

            $this->runCommand($browser, 'Allowed values');
            $browser->waitFor('#allowedbar:not([hidden])');
            $browser->assertSeeIn('#allow-where', 'Let C5:C6 hold');
            $browser->assertSeeIn('#allowed-list', 'Any value can go anywhere');

            $browser->select('#allow-type', 'list');
            $browser->keys('#allow-items', 'Paid, Unpaid, Late', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#allowed-list', 'C5:C6, one of Paid, Unpaid, Late');
            $this->assertSame(5, $tab->fresh()->layout['validation_rules'][0]['row2']);

            // The chevron is on the cells the rule covers and nowhere else.
            $this->clickCell($browser, 5, 2);
            $browser->waitFor('#pick:not([hidden])');
            foreach ([[6, 2], [5, 3], [5, 1]] as [$row, $col]) {
                $this->clickCell($browser, 5, 2);
                $browser->waitFor('#pick:not([hidden])');
                $this->clickCell($browser, $row, $col);
                $browser->waitUsing(5, 25, fn () => $browser->driver->executeScript("return document.getElementById('pick').hidden"));
            }

            // Choosing writes the value.
            $this->clickCell($browser, 5, 2);
            $browser->waitFor('#pick:not([hidden])');
            $this->pressOn($browser, '#pick');
            $browser->waitFor('#picks:not([hidden])');
            $browser->assertSeeIn('#picks', 'Unpaid');
            $this->assertSame('Paid', $browser->driver->executeScript('return document.activeElement.textContent'));
            $browser->driver->executeScript("[...document.querySelectorAll('#picks button')].find(b => b.textContent === 'Late').click()");
            $browser->waitUsing(10, 25, fn () => $at(5, 2)?->raw_value === 'Late');
            $browser->assertMissing('#picks:not([hidden])');
            $browser->assertSeeIn('#ref', 'C6');

            // The keyboard opens it, moves through it and chooses.
            // Sent to the page, as a keyboard does: aiming at an element makes the driver focus it again.
            $press = fn (string ...$keys) => $browser->driver->getKeyboard()->sendKeys($keys);
            $press(WebDriverKeys::ALT, WebDriverKeys::ARROW_DOWN);
            $press(WebDriverKeys::NULL);
            $browser->waitFor('#picks:not([hidden])');
            $this->assertSame('Late', $browser->driver->executeScript('return document.activeElement.textContent'));
            $press(WebDriverKeys::ARROW_DOWN);
            $this->assertSame('Paid', $browser->driver->executeScript('return document.activeElement.textContent'));
            $press(WebDriverKeys::ESCAPE);
            $browser->assertMissing('#picks:not([hidden])');
            $this->assertSame('sheet', $browser->driver->executeScript('return document.activeElement.id'));

            // Anything else is refused, and says what would do.
            $browser->keys('#sheet', 'Maybe', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#note', 'Choose from the list: Paid, Unpaid, Late');
            $this->assertSame('Late', $at(5, 2)?->raw_value);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_chevron_goes_while_a_cell_is_edited_and_a_list_opens_upward_near_the_foot(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $tab->forceFill(['layout' => ['validation_rules' => [['id' => 'a', 'row' => 8, 'col' => 1, 'type' => 'list', 'items' => 'One, Two, Three, Four, Five, Six']]]])->save();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $browser->resize(1280, 640);
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 8, 1);
            $browser->waitFor('#pick:not([hidden])');

            $browser->keys('#sheet', WebDriverKeys::F2);
            $browser->waitUsing(5, 25, fn () => $browser->driver->executeScript("return document.getElementById('pick').hidden"));
            $browser->keys('.edit', WebDriverKeys::ESCAPE);
            $browser->waitFor('#pick:not([hidden])');

            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::ALT, WebDriverKeys::ARROW_DOWN]);
            $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::NULL]);
            $browser->waitFor('#picks:not([hidden])');
            $inside = $browser->driver->executeScript(<<<'JS'
                const list = document.getElementById('picks').getBoundingClientRect();
                const sheet = document.getElementById('sheet').getBoundingClientRect();
                return list.bottom <= sheet.bottom && list.top >= sheet.top;
            JS);
            $this->assertTrue((bool) $inside, 'The whole list shows.');
            $browser->resize(1280, 800);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_number_range_is_set_and_a_rule_can_be_removed_and_put_back(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 4, 1);
            $this->runCommand($browser, 'validation');
            $browser->waitFor('#allowedbar:not([hidden])');
            $browser->assertSeeIn('#allow-where', 'Let this cell hold');

            $browser->keys('#allow-min', '1');
            $browser->keys('#allow-max', '5', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#allowed-list', 'B5, numbers from 1 to 5');

            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', '9', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#note', 'That is too high: the most allowed here is 5');

            $browser->click('#allowed-list .rule button');
            $browser->waitForTextIn('#allowed-list', 'Any value can go anywhere');
            $this->assertSame([], $tab->fresh()->layout['validation_rules']);

            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitForTextIn('#allowed-list', 'B5, numbers from 1 to 5');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_view_gets_no_chevron(): void
    {
        [, $sheet, $tab] = $this->sheet();
        $tab->forceFill(['layout' => ['validation_rules' => [['id' => 'a', 'row' => 0, 'col' => 0, 'type' => 'list', 'items' => 'One']]]])->save();
        $viewer = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($viewer, $sheet) {
            $this->open($browser, $viewer, $sheet);
            $this->clickCell($browser, 0, 0);
            $browser->pause(100)->assertMissing('#pick:not([hidden])');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_sorting_keeps_the_heading_and_the_formulas_right_and_undo_puts_it_back(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        Cell::where('sheet_tab_id', $tab->id)->delete();
        app(CellWriter::class)->write($sheet->id, $tab->id, [
            ['row' => 0, 'col' => 0, 'value' => 'Sales'], ['row' => 0, 'col' => 1, 'value' => 'Price'], ['row' => 0, 'col' => 2, 'value' => 'Double'],
            ['row' => 1, 'col' => 0, 'value' => 'Pears'], ['row' => 1, 'col' => 1, 'value' => '30'], ['row' => 1, 'col' => 2, 'value' => '=B2*2'],
            ['row' => 2, 'col' => 0, 'value' => 'Apples'], ['row' => 2, 'col' => 1, 'value' => '10'], ['row' => 2, 'col' => 2, 'value' => '=B3*2'],
            ['row' => 3, 'col' => 0, 'value' => 'Figs'], ['row' => 3, 'col' => 1, 'value' => '20'], ['row' => 3, 'col' => 2, 'value' => '=B4*2'],
        ], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 2, 1);

            $this->runCommand($browser, 'Sort A to Z');
            $browser->waitForTextIn('#note', 'Sorted rows 2 to 4 by column B, keeping row 1 as a heading');

            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 1, 0) === 'Apples');
            $this->assertSame('Sales', $this->cell($browser, 0, 0));
            $this->assertSame('Figs', $this->cell($browser, 2, 0));
            $this->assertSame('Pears', $this->cell($browser, 3, 0));
            $this->assertSame('20', $this->cell($browser, 1, 2));
            $this->assertSame('60', $this->cell($browser, 3, 2));

            $this->runCommand($browser, 'Sort Z to A');
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 1, 0) === 'Pears');
            $this->assertSame('60', $this->cell($browser, 1, 2));

            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 1, 0) === 'Apples');
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 2, 0) === 'Apples');
            $this->assertSame('Pears', $this->cell($browser, 1, 0));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_sort_that_cannot_be_clean_says_why_and_moves_nothing(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $tab->forceFill(['layout' => ['merges' => [['r1' => 1, 'r2' => 1, 'c1' => 0, 'c2' => 1]]]])->save();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 2, 1);
            $this->runCommand($browser, 'Sort A to Z');
            $browser->waitForTextIn('#note', 'These rows have merged cells');
            $this->assertSame('Net', $this->cell($browser, 2, 0));
        });
    }

    public function test_a_filter_hides_rows_keeps_the_heading_and_waits_to_be_reapplied(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 1, 0);
            $this->runCommand($browser, 'Filter rows');
            $browser->waitFor('#filterbar:not([hidden])');
            $browser->assertSeeIn('#filter-where', 'Show rows where column A is');
            $browser->assertSeeIn('#filter-list', 'Every row is showing');

            $browser->keys('#filter-value', 'net', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#filter-list', 'Column A, containing net');
            $browser->waitForTextIn('#note', '1 row hidden');
            $this->assertSame([1], $tab->fresh()->layout['hidden_rows']);
            $this->assertNull($this->placeOf($browser, 'Rent'));
            $this->assertNotNull($this->placeOf($browser, 'Sales'), 'The heading stays.');
            $browser->assertSeeIn('#ref', 'A3');

            // A cell changed so it no longer matches stays until the filter runs again.
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', 'Total', WebDriverKeys::ENTER);
            $browser->waitUsing(10, 25, fn () => $this->placeOf($browser, 'Total') !== null);
            $this->assertSame([1], $tab->fresh()->layout['hidden_rows']);

            $browser->click('#filter-reapply');
            $browser->waitUsing(10, 25, fn () => $tab->fresh()->layout['hidden_rows'] === [1, 2]);
            $browser->waitUsing(10, 25, fn () => $this->placeOf($browser, 'Total') === null);

            // Clear brings them back, and undo puts the filter on again.
            $browser->click('#filter-clear');
            $browser->waitForTextIn('#filter-list', 'Every row is showing');
            $browser->waitUsing(10, 25, fn () => $this->placeOf($browser, 'Rent') !== null);

            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitForTextIn('#filter-list', 'Column A, containing net');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_view_cannot_sort_or_filter(): void
    {
        [, $sheet] = $this->sheet();
        $viewer = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($viewer, $sheet) {
            $this->open($browser, $viewer, $sheet);
            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'Sort');
            $browser->pause(100)->assertDontSeeIn('#found', 'Sort A to Z');
            $this->assertNoConsoleErrors($browser);
        });
    }

    /** What the cells that carry a comment's mark hold, and whether the mark is really drawn. */
    private function marked(Browser $browser): array
    {
        return $browser->driver->executeScript(<<<'JS'
            return [...document.querySelectorAll('.c.noted')].filter(c => getComputedStyle(c).backgroundImage !== 'none').map(c => c.textContent);
        JS);
    }

    private function say(Browser $browser, string $text): void
    {
        $browser->type('#notes-text', $text)->keys('#notes-text', '{enter}');
    }

    public function test_a_comment_is_written_marked_and_still_there_after_a_reload(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->clickCell($browser, 1, 1);
            $browser->assertVisible('#do-comments')->assertMissing('#notes-count')->click('#do-comments')->waitFor('#notes');
            $browser->assertSeeIn('#notes-on', 'Comment on B2')->assertSeeIn('#notes-list', 'No comments on this sheet yet.');

            $this->say($browser, 'Rent went up in March');
            $browser->waitForTextIn('#notes-list', 'Rent went up in March');
            $this->assertSame(['-400'], array_map(fn ($text) => str_replace('−', '-', $text), $this->marked($browser)));
            $browser->assertSeeIn('#notes-list .thread.here .where', 'B2')->assertSeeIn('#notes-count', '1');
            $this->assertSame('', $browser->value('#notes-text'), 'the box is ready for the next one');
            $this->assertSame('-400', str_replace('−', '-', $this->cell($browser, 1, 1)), 'typing there leaves the sheet alone');

            $browser->driver->navigate()->refresh();
            $browser->waitUsing(10, 25, fn () => count($this->marked($browser)) === 1);
            $browser->assertMissing('#notes')->assertSeeIn('#notes-count', '1');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_panel_moves_the_sheet_aside_and_never_covers_it(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $rects = fn () => $browser->driver->executeScript(<<<'JS'
                const rect = (id) => document.getElementById(id).getBoundingClientRect();
                return { sheetRight: rect('sheet').right, sheetBottom: rect('sheet').bottom, notesLeft: rect('notes').left, notesTop: rect('notes').top, wide: document.documentElement.scrollWidth <= innerWidth };
            JS);
            $width = $rects()['sheetRight'];

            $browser->click('#do-comments')->waitFor('#notes');
            $open = $rects();
            $this->assertLessThanOrEqual($open['notesLeft'], $open['sheetRight']);
            $this->assertLessThan($width, $open['sheetRight']);

            $browser->click('#notes-close');
            $browser->waitUntilMissing('#notes');
            $this->assertSame($width, $rects()['sheetRight']);

            $browser->resize(390, 780);
            $browser->pause(100);
            $this->runCommand($browser, 'Comments');
            $browser->waitFor('#notes');
            $phone = $rects();
            $this->assertLessThanOrEqual($phone['notesTop'], $phone['sheetBottom'], 'under the sheet on a phone');
            $this->assertTrue($phone['wide']);
            $browser->resize(1280, 800);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_thread_takes_a_reply_is_resolved_and_reopened_and_can_be_deleted(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $other = User::factory()->withPersonalTeam()->create(['name' => 'Thandi']);
        $sheet->sharedUsers()->attach($other->id, ['permission' => 'comment']);
        $thread = app(SheetComments::class)->add($sheet->id, $tab->id, $other->id, 0, 0, 'Theirs');
        app(SheetComments::class)->add($sheet->id, $tab->id, $user->id, 0, 1, 'Mine');

        $this->browse(function (Browser $browser) use ($user, $sheet, $thread) {
            $this->open($browser, $user, $sheet);
            $browser->click('#do-comments')->waitForTextIn('#notes-list', 'Theirs');
            $first = "#notes-list .thread[data-id='{$thread->id}']";
            $browser->assertSeeIn($first, 'Thandi')->assertDontSeeIn($first, 'Delete');
            $browser->assertSeeIn('#notes-list .thread:nth-child(2)', 'You')->assertSeeIn('#notes-list .thread:nth-child(2)', 'Delete');

            $browser->pause(50);
            $this->pressIn($browser, $first, 'Reply');
            $browser->waitFor("{$first} .reply input")->type("{$first} .reply input", 'Agreed')->keys("{$first} .reply input", '{enter}');
            $browser->waitForTextIn($first, 'Agreed');
            $browser->assertMissing("{$first} .reply");

            $this->pressIn($browser, $first, 'Resolve');
            $browser->waitForTextIn('#notes-list', 'Show 1 resolved');
            $browser->assertSeeIn('#notes-count', '1');
            $this->assertCount(1, $this->marked($browser));
            $browser->assertMissing($first);

            $browser->driver->executeScript("document.querySelector('#notes-list .notes-more button').focus()");
            $this->pressIn($browser, '#notes-list', 'Show 1 resolved');
            $browser->waitFor($first);
            $this->assertSame('Hide 1 resolved', $browser->driver->executeScript('return document.activeElement.textContent'), 'the keyboard stays where it was');
            $this->pressIn($browser, $first, 'Reopen');
            $browser->waitForTextIn('#notes-count', '2');
            $this->assertCount(2, $this->marked($browser));

            $mine = '#notes-list .thread:nth-child(2)';
            $this->pressIn($browser, $mine, 'Delete');
            $browser->waitFor('#confirm[open]')->assertSeeIn('#confirm-text', 'Delete this comment?')->assertSeeIn('#confirm-yes', 'Delete');
            $browser->click('#confirm-yes');
            $browser->waitForTextIn('#notes-count', '1');
            $browser->assertDontSeeIn('#notes-list', 'Mine');
            $this->assertNoConsoleErrors($browser);
        });
    }

    private function pressIn(Browser $browser, string $within, string $label): void
    {
        $browser->driver->executeScript(<<<'JS'
            [...document.querySelector(arguments[0]).querySelectorAll('button')].find(b => b.textContent === arguments[1]).click();
        JS, [$within, $label]);
    }

    public function test_a_comment_goes_to_its_cell_and_a_cell_opens_its_comments(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        app(SheetComments::class)->add($sheet->id, $tab->id, $user->id, 2, 1, 'On the net');

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->assertMissing('#noted-here');

            $this->clickCell($browser, 2, 1);
            $browser->waitFor('#noted-here');
            $this->assertSame('Open the comment on B3', $browser->attribute('#noted-here', 'aria-label'));
            $this->clickCell($browser, 0, 0);
            $browser->waitUntilMissing('#noted-here');
            $this->clickCell($browser, 2, 1);
            $browser->click('#noted-here')->waitForTextIn('#notes-list', 'On the net');
            $browser->assertPresent('#notes-list .thread.here');

            $this->clickCell($browser, 0, 0);
            $browser->waitUntilMissing('#notes-list .thread.here');
            $browser->assertSeeIn('#notes-on', 'Comment on A1');

            $browser->click('#notes-list .where');
            $browser->waitFor('#notes-list .thread.here');
            $browser->assertSeeIn('#ref', 'B3');
            $this->assertSame('sheet', $browser->driver->executeScript('return document.activeElement.id'), 'the arrow keys carry on from there');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_comment_keeps_to_its_row_through_a_sort_and_undo(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        Cell::where('sheet_tab_id', $tab->id)->delete();
        app(CellWriter::class)->write($sheet->id, $tab->id, [
            ['row' => 0, 'col' => 0, 'value' => 'Sales'], ['row' => 0, 'col' => 1, 'value' => 'Price'],
            ['row' => 1, 'col' => 0, 'value' => 'Pears'], ['row' => 1, 'col' => 1, 'value' => '30'],
            ['row' => 2, 'col' => 0, 'value' => 'Apples'], ['row' => 2, 'col' => 1, 'value' => '10'],
        ], $user->id);
        app(SheetComments::class)->add($sheet->id, $tab->id, $user->id, 1, 0, 'About pears');

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->waitUsing(10, 25, fn () => $this->marked($browser) === ['Pears']);
            $browser->click('#do-comments')->waitForTextIn('#notes-list .where', 'A2');
            $this->clickCell($browser, 1, 0);

            $this->runCommand($browser, 'Sort A to Z');
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 2, 0) === 'Pears');
            $browser->waitForTextIn('#notes-list .where', 'A3');
            $this->assertSame(['Pears'], $this->marked($browser));

            $browser->script("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 1, 0) === 'Pears');
            $browser->waitForTextIn('#notes-list .where', 'A2');
            $this->assertSame(['Pears'], $this->marked($browser));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_merged_cell_shows_the_comment_of_a_cell_inside_it(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        Cell::where('sheet_tab_id', $tab->id)->where('col_index', 1)->delete();
        app(SheetComments::class)->add($sheet->id, $tab->id, $user->id, 0, 1, 'Inside the merge');
        SheetTab::updateLayout($tab->id, fn (array $layout) => [...$layout, 'merges' => [['r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1]]]);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->waitUsing(10, 25, fn () => $this->marked($browser) === ['Sales']);
            $this->clickCell($browser, 0, 0);
            $browser->click('#noted-here')->waitForTextIn('#notes-list', 'Inside the merge');
            $browser->assertPresent('#notes-list .thread.here');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_link_from_an_email_opens_the_sheet_the_cell_and_the_comments(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $extra = SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Stock', 'sort_order' => 2]);
        app(SheetComments::class)->add($sheet->id, $extra->id, $user->id, 3, 2, 'Over here');

        $this->browse(function (Browser $browser) use ($user, $sheet, $extra) {
            $browser->driver->get('about:blank');
            $browser->driver->manage()->getLog('browser');
            $browser->loginAs($user)->visit("/spreadsheets/{$sheet->id}?tab={$extra->id}&cell=C4");
            $browser->waitForTextIn('#notes-list', 'Over here');
            $browser->assertSeeIn('#ref', 'C4')->assertPresent('#notes-list .thread.here')->assertSeeIn('#tabs [aria-current]', 'Stock');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_keys_open_comments_and_escape_closes_them_and_text_stays_in_the_box(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            // keys() puts the focus back on the element it was given: this is a key that moves it.
            $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::CONTROL, WebDriverKeys::ALT, 'm']);
            $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::NULL]);
            $browser->waitFor('#notes');
            $this->assertSame('notes-text', $browser->driver->executeScript('return document.activeElement.id'));

            $kept = $browser->driver->executeScript(<<<'JS'
                const box = document.getElementById('notes-text');
                const cut = new ClipboardEvent('cut', { clipboardData: new DataTransfer(), bubbles: true, cancelable: true });
                box.dispatchEvent(cut);
                return !cut.defaultPrevented;
            JS);
            $this->assertTrue($kept, 'cutting in the box is the box\'s own business');

            $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::ESCAPE]);
            $browser->waitUntilMissing('#notes');
            $this->assertSame('sheet', $browser->driver->executeScript('return document.activeElement.id'));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_view_reads_comments_and_writes_none(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        app(SheetComments::class)->add($sheet->id, $tab->id, $user->id, 0, 0, 'Worth a look');
        $reader = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($reader, $sheet) {
            $this->open($browser, $reader, $sheet);
            $this->runCommand($browser, 'Comments');
            $browser->waitForTextIn('#notes-list', 'Worth a look');
            $browser->assertMissing('#notes-form')->assertMissing('#notes-list .deeds');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_sheet_is_shared_from_the_panel_and_what_someone_can_do_changes(): void
    {
        [$user, $sheet] = $this->sheet();
        $friend = User::factory()->withPersonalTeam()->create(['name' => 'Thandi', 'email' => 'thandi@example.test']);

        $this->browse(function (Browser $browser) use ($user, $sheet, $friend) {
            $this->open($browser, $user, $sheet);
            $browser->click('#do-share')->waitForTextIn('#share-people', $user->name);
            $browser->assertSeeIn('#share-people', '(You)')->assertSeeIn('#share-people', 'Owner');
            $this->assertSame('share-email', $browser->driver->executeScript('return document.activeElement.id'));

            $browser->type('#share-email', 'nonsense')->keys('#share-email', '{enter}');
            $browser->waitForTextIn('#note', 'does not look like an email address');
            $browser->assertValue('#share-email', 'nonsense');

            $browser->type('#share-email', 'thandi@example.test')->select('#share-permission', 'comment')->keys('#share-email', '{enter}');
            $browser->waitForTextIn('#share-people', 'Thandi');
            $browser->assertSeeIn('#note', 'Shared with thandi@example.test')->assertValue('#share-email', '');
            $this->assertSame('comment', $sheet->sharedUsers()->whereKey($friend->id)->first()->pivot->permission);

            $browser->select('#share-people select', 'edit');
            $browser->waitForTextIn('#note', 'Changed what Thandi can do');
            $this->assertSame('edit', $sheet->sharedUsers()->whereKey($friend->id)->first()->pivot->permission);

            $this->pressIn($browser, '#share-people', 'Remove');
            $browser->waitForTextIn('#note', 'Removed Thandi');
            // The refused address above logs an error by itself, so the console is not checked here.
            $browser->assertDontSeeIn('#share-people', 'Thandi');
        });
    }

    public function test_someone_with_no_account_gets_a_link_to_pass_on(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Share this sheet');
            $browser->waitForTextIn('#share-people', $user->name)->assertMissing('#share-waiting-block');

            $browser->type('#share-email', 'new@example.test')->keys('#share-email', '{enter}');
            $browser->waitForTextIn('#share-waiting', 'new@example.test');
            $browser->assertSeeIn('#note', 'has no account here yet')->assertSeeIn('#share-waiting', 'Can read')->assertSeeIn('#share-waiting', 'Copy link');

            $this->pressIn($browser, '#share-waiting', 'Cancel');
            $browser->waitUntilMissing('#share-waiting-block');
            $this->assertSame(0, SpreadsheetInvitation::where('spreadsheet_id', $sheet->id)->count());
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_link_for_anyone_goes_on_off_and_changes_after_asking(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->click('#do-share')->waitForTextIn('#share-people', $user->name)->assertMissing('#share-link-rest');
            $browser->assertSeeIn('#sharing', 'can read the first sheet');

            $browser->click('#share-link-on')->waitFor('#share-link-rest');
            $browser->waitUsing(10, 25, fn () => $browser->value('#share-link-url') !== '');
            $first = $browser->value('#share-link-url');
            $this->assertStringContainsString('/shared/s/', $first);

            $browser->click('#share-link-new')->waitFor('#confirm[open]')->assertSeeIn('#confirm-yes', 'Make a new link');
            $browser->click('#confirm [value=cancel]')->pause(100);
            $this->assertSame($first, $browser->value('#share-link-url'), 'keeping it is the default');

            $browser->click('#share-link-new')->waitFor('#confirm[open]')->click('#confirm-yes');
            $browser->waitUsing(10, 25, fn () => $browser->value('#share-link-url') !== $first);
            $browser->assertSeeIn('#note', 'The old one no longer works');

            $browser->click('#share-link-on')->waitUntilMissing('#share-link-rest');
            $this->assertFalse($sheet->fresh()->public_enabled);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_an_end_time_is_read_on_the_person_s_own_clock(): void
    {
        [$user, $sheet] = $this->sheet();
        $sheet->update(['public_enabled' => true, 'public_token' => 'tok']);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->click('#do-share')->waitFor('#share-link-rest');

            $at = now()->addDays(2)->startOfMinute();
            $browser->driver->executeScript(<<<'JS'
                const at = new Date(arguments[0]);
                const field = document.getElementById('share-link-expiry');
                field.value = new Date(at.getTime() - at.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                field.dispatchEvent(new Event('change', { bubbles: true }));
            JS, [$at->toIso8601String()]);
            $browser->waitForTextIn('#note', 'The link is on');

            $this->assertTrue($at->equalTo($sheet->fresh()->public_expires_at));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_one_panel_is_open_at_a_time(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->click('#do-comments')->waitFor('#notes');
            $browser->click('#do-share')->waitFor('#sharing')->assertMissing('#notes');
            $this->assertSame('false', $browser->attribute('#do-comments', 'aria-expanded'));

            $browser->click('#do-comments')->waitFor('#notes')->assertMissing('#sharing');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_cannot_manage_sharing_gets_no_way_to_it(): void
    {
        [$user, $sheet] = $this->sheet();
        $editor = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($editor->id, ['permission' => 'edit']);

        $this->browse(function (Browser $browser) use ($editor, $sheet) {
            $this->open($browser, $editor, $sheet);
            $browser->assertMissing('#do-share')->assertMissing('#sharing');
            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'share this');
            $browser->pause(100)->assertDontSeeIn('#found', 'Share this sheet');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_copy_is_saved_and_put_back_after_saying_what_changes(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Version history');
            $browser->waitFor('#copies')->waitForTextIn('#copies-whose', 'Saved copies of Sheet1')->assertSeeIn('#copies-list', 'No copies yet');
            $this->assertSame('copies-label', $browser->driver->executeScript('return document.activeElement.id'));

            $browser->type('#copies-label', 'Month end')->keys('#copies-label', '{enter}');
            $browser->waitForTextIn('#copies-list', 'Month end');
            $browser->assertValue('#copies-label', '')->assertSeeIn('#note', 'Saved a copy');

            app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 0, 'col' => 0, 'value' => 'Changed']], $user->id);

            $this->pressIn($browser, '#copies-list', 'Put back');
            $browser->waitFor('#confirm[open]')->assertSeeIn('#confirm-text', 'Put back Month end')->assertSeeIn('#confirm-text', '1 cell changes')
                ->assertSeeIn('#confirm-text', 'kept as a copy')->assertSeeIn('#confirm-yes', 'Put it back')->assertSeeIn('#confirm-no', 'Leave the sheet as it is');
            $this->assertNotSame('rgba(0, 0, 0, 0)', $browser->driver->executeScript("return getComputedStyle(document.getElementById('confirm-yes')).backgroundColor"), 'the action is solid ink');

            $browser->click('#confirm [value=cancel]')->pause(100);
            $this->assertSame('Changed', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 0)->value('raw_value'), 'keeping it is the default');

            $this->pressIn($browser, '#copies-list', 'Put back');
            $browser->waitFor('#confirm[open]')->click('#confirm-yes');
            $browser->waitForTextIn('#note', 'Put back Month end');
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 0) === 'Sales');
            $browser->waitForTextIn('#copies-list', 'Before restoring');
            $this->assertSame('Sales', Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 0)->value('raw_value'));

            // The sheet goes on working: the version in the page moved with it.
            $this->clickCell($browser, 0, 0);
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', 'Typed', '{enter}');
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 0) === 'Typed');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_list_follows_the_sheet_in_view_and_only_one_panel_opens(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $extra = SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Stock', 'sort_order' => 2]);
        app(SheetVersions::class)->snapshot($sheet->id, $tab->id, $user->id, SheetVersions::AUTO, 'Auto Snapshot');
        app(SheetVersions::class)->snapshot($sheet->id, $extra->id, $user->id, SheetVersions::MANUAL, 'Stock take');

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->click('#do-comments')->waitFor('#notes');
            $this->runCommand($browser, 'Version history');
            $browser->waitForTextIn('#copies-list', 'Automatic copy')->assertMissing('#notes');

            $browser->driver->executeScript("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Stock').click()");
            $browser->waitForTextIn('#copies-list', 'Stock take')->assertDontSeeIn('#copies-list', 'Automatic copy')->assertSeeIn('#copies-whose', 'Saved copies of Stock');

            $browser->click('#do-share')->waitFor('#sharing')->assertMissing('#copies');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_view_reads_the_history_and_changes_nothing(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        app(SheetVersions::class)->snapshot($sheet->id, $tab->id, $user->id, SheetVersions::MANUAL, 'Month end');
        $reader = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($reader, $sheet) {
            $this->open($browser, $reader, $sheet);
            $this->runCommand($browser, 'Version history');
            $browser->waitForTextIn('#copies-list', 'Month end');
            $browser->assertMissing('#copies-form')->assertMissing('#copies-list .deeds');
            $this->assertNoConsoleErrors($browser);
        });
    }

    /** A file on disk for the browser to pick. */
    private function fileToImport(string $name, string $contents): string
    {
        $path = sys_get_temp_dir().'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_a_file_comes_in_after_saying_what_it_writes_over_and_the_copy_is_in_history(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $path = $this->fileToImport('figures.csv', "Fresh,1\nNew,2\n");

        $this->browse(function (Browser $browser) use ($user, $sheet, $path) {
            $this->open($browser, $user, $sheet);

            // The file dialog is the browser's own, so the test stops at the moment it would open.
            $browser->driver->executeScript("document.getElementById('import-file').addEventListener('click', (e) => { e.preventDefault(); window.picking = true; }, { once: true })");
            $this->runCommand($browser, 'Import a file');
            $browser->waitUsing(5, 25, fn () => $browser->driver->executeScript('return window.picking === true'));

            $browser->attach('#import-file', $path);
            $browser->waitFor('#confirm[open]')->assertSeeIn('#confirm-text', 'Import figures.csv?')->assertSeeIn('#confirm-text', 'writes over Sheet1 from A1')
                ->assertSeeIn('#confirm-text', 'kept as a copy')->assertDontSeeIn('#confirm-text', 'Excel')->assertSeeIn('#confirm-yes', 'Import');

            $browser->click('#confirm-no')->pause(100);
            $this->assertSame('Sales', $this->cell($browser, 0, 0), 'leaving it is the default');

            $browser->attach('#import-file', $path);
            $browser->waitFor('#confirm[open]')->click('#confirm-yes');
            $browser->waitForTextIn('#note', 'Imported 2 rows. The sheet as it was is in Version history.');
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 0) === 'Fresh');
            $this->assertSame('Net', $this->cell($browser, 2, 0), 'cells outside the file stay');

            $this->runCommand($browser, 'Version history');
            $browser->waitForTextIn('#copies-list', 'Before importing figures.csv');
            $this->pressIn($browser, '#copies-list', 'Put back');
            $browser->waitFor('#confirm[open]')->click('#confirm-yes');
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 0) === 'Sales');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_an_empty_sheet_takes_a_file_without_asking(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        Cell::where('sheet_tab_id', $tab->id)->delete();
        $path = $this->fileToImport('first.csv', "Sales,10\n");

        $this->browse(function (Browser $browser) use ($user, $sheet, $path) {
            $browser->driver->get('about:blank');
            $browser->driver->manage()->getLog('browser');
            $browser->loginAs($user)->visit("/spreadsheets/{$sheet->id}")->waitFor('.c');
            $browser->attach('#import-file', $path);
            $browser->waitForTextIn('#note', 'Imported 1 row.');
            $browser->assertMissing('#confirm[open]')->assertDontSeeIn('#note', 'Version history');
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 0) === 'Sales');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_an_excel_file_says_what_it_will_lose_first(): void
    {
        [$user, $sheet] = $this->sheet();
        $path = $this->fileToImport('book.xlsx', 'this is not a workbook');

        $this->browse(function (Browser $browser) use ($user, $sheet, $path) {
            $this->open($browser, $user, $sheet);
            $browser->attach('#import-file', $path);
            $browser->waitFor('#confirm[open]')->assertSeeIn('#confirm-text', 'Only the first sheet of an Excel file comes in, and its formulas come in as text.');
            $browser->click('#confirm-yes');
            $browser->waitForTextIn('#note', 'That file could not be read.');
            $this->assertSame('Sales', $this->cell($browser, 0, 0));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_large_file_shows_its_progress_and_says_when_it_stops(): void
    {
        [$user, $sheet] = $this->sheet();
        $path = $this->fileToImport('large.csv', "a,b\n");

        $this->browse(function (Browser $browser) use ($user, $sheet, $path) {
            $this->open($browser, $user, $sheet);

            // The queue is not running here, so the server's two answers are played back to the page.
            $browser->driver->executeScript(<<<'JS'
                const real = window.fetch;
                let polls = 0;
                const answer = (body) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
                window.fetch = (url, options) => {
                    if (String(url).endsWith('/import') && options?.method === 'POST') return Promise.resolve(answer({ success: true, queued: true, batch_id: 'b1', rows: 3000, copied: true }));
                    if (String(url).endsWith('/import/batches/b1')) {
                        polls++;
                        return Promise.resolve(answer(polls === 1 ? { status: 'running', progress: 40 } : { status: 'failed', message: 'Import failed after 1200 of 3000 rows; the rows already written are kept.' }));
                    }
                    return real(url, options);
                };
            JS);

            $browser->attach('#import-file', $path);
            $browser->waitFor('#confirm[open]')->click('#confirm-yes');
            $browser->waitForTextIn('#note', 'Importing large.csv: 40%', 5);
            $browser->waitForTextIn('#note', 'Import failed after 1200 of 3000 rows', 5);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_view_cannot_import(): void
    {
        [$user, $sheet] = $this->sheet();
        $reader = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($reader, $sheet) {
            $this->open($browser, $reader, $sheet);
            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'import');
            $browser->pause(100)->assertDontSeeIn('#found', 'Import a file');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_selection_is_named_a_formula_uses_it_and_deleting_it_says_what_breaks(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 3, 'col' => 1, 'value' => '=SUM(Figures)']], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 3, 1) === '#NAME?');
            $this->runCommand($browser, 'Named ranges');
            $browser->waitFor('#names')->waitForTextIn('#names-list', 'No names on this sheet yet');
            $this->assertSame('names-name', $browser->driver->executeScript('return document.activeElement.id'));
            $browser->assertValue('#names-range', 'A1');

            $this->clickCell($browser, 0, 1);
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::SHIFT, WebDriverKeys::ARROW_DOWN]);
            $browser->waitUsing(5, 25, fn () => $browser->value('#names-range') === 'B1:B2');

            $browser->type('#names-name', 'Figures')->keys('#names-name', '{enter}');
            $browser->waitForTextIn('#names-list', 'Figures');
            $browser->assertSeeIn('#names-list', 'B1:B2')->assertSeeIn('#note', 'Figures now stands for B1:B2')->assertValue('#names-name', '');
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 3, 1) === '600');

            $this->clickCell($browser, 2, 0);
            $browser->driver->executeScript("document.getElementById('names-name').focus()");
            $this->pressIn($browser, '#names-list', 'Go to');
            $browser->waitUsing(5, 25, fn () => $browser->text('#ref') === 'B1');
            $this->assertSame('sheet', $browser->driver->executeScript('return document.activeElement.id'));

            $this->pressIn($browser, '#names-list', 'Delete');
            $browser->waitFor('#confirm[open]')->assertSeeIn('#confirm-text', 'Delete Figures? 1 formula uses it and will show #NAME?.')->assertSeeIn('#confirm-yes', 'Delete name');
            $browser->click('#confirm-yes');
            $browser->waitForTextIn('#note', 'Deleted Figures.');
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 3, 1) === '#NAME?');
            $browser->assertSeeIn('#names-list', 'No names on this sheet yet');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_range_someone_typed_stays_while_the_selection_moves(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Named ranges');
            $browser->waitFor('#names')->type('#names-range', 'C1:C9');
            $this->clickCell($browser, 2, 1);
            $browser->pause(100)->assertValue('#names-range', 'C1:C9');

            $browser->type('#names-name', 'sum')->keys('#names-name', '{enter}');
            $browser->waitForTextIn('#note', 'function name')->assertValue('#names-name', 'sum');
        });
    }

    public function test_the_names_follow_the_sheet_in_view_and_one_panel_opens(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        $extra = SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Stock', 'sort_order' => 2]);
        app(SheetNames::class)->add($sheet->id, $tab->id, 'Sales', 'A1');
        app(SheetNames::class)->add($sheet->id, $extra->id, 'Shelf', 'B2');

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->click('#do-comments')->waitFor('#notes');
            $this->runCommand($browser, 'Named ranges');
            $browser->waitForTextIn('#names-list', 'Sales')->assertMissing('#notes');

            $browser->driver->executeScript("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Stock').click()");
            $browser->waitForTextIn('#names-list', 'Shelf')->assertDontSeeIn('#names-list', 'Sales');

            $browser->click('#do-share')->waitFor('#sharing')->assertMissing('#names');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_view_reads_names_and_goes_to_them(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        app(SheetNames::class)->add($sheet->id, $tab->id, 'Figures', 'B2');
        $reader = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($reader, $sheet) {
            $this->open($browser, $reader, $sheet);
            $this->runCommand($browser, 'Named ranges');
            $browser->waitForTextIn('#names-list', 'Figures')->assertMissing('#names-form')->assertDontSeeIn('#names-list', 'Delete');
            $this->pressIn($browser, '#names-list', 'Go to');
            $browser->waitUsing(5, 25, fn () => $browser->text('#ref') === 'B2');
            $this->assertNoConsoleErrors($browser);
        });
    }

    /** How much of each chart colour a chart really painted. A canvas holds no text to read. */
    private function paint(Browser $browser): array
    {
        return $browser->driver->executeScript(<<<'JS'
            const canvas = document.getElementById('charts-canvas');
            if (!canvas.width) return {};
            const pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
            const styles = getComputedStyle(document.documentElement);
            const wanted = [1, 2, 3, 4, 5, 6].map((n) => styles.getPropertyValue(`--chart-${n}`).trim());
            const parts = wanted.map((hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)));
            const counts = {};
            for (let i = 0; i < pixels.length; i += 4) {
                if (pixels[i + 3] < 250) continue;
                parts.forEach((rgb, n) => {
                    if (Math.abs(pixels[i] - rgb[0]) < 6 && Math.abs(pixels[i + 1] - rgb[1]) < 6 && Math.abs(pixels[i + 2] - rgb[2]) < 6) counts[n + 1] = (counts[n + 1] ?? 0) + 1;
                });
            }
            return counts;
        JS);
    }

    private function chartTable(Browser $browser): array
    {
        return $browser->driver->executeScript(<<<'JS'
            return [...document.querySelectorAll('#charts-table tr')].map((row) => [...row.children].map((cell) => cell.textContent));
        JS);
    }

    /** @return array{0: User, 1: Spreadsheet, 2: SheetTab} */
    private function salesSheet(): array
    {
        [$user, $sheet, $tab] = $this->sheet();
        Cell::where('sheet_tab_id', $tab->id)->delete();
        app(CellWriter::class)->write($sheet->id, $tab->id, [
            ['row' => 0, 'col' => 0, 'value' => 'Month'], ['row' => 0, 'col' => 1, 'value' => 'Sales'], ['row' => 0, 'col' => 2, 'value' => 'Costs'],
            ['row' => 1, 'col' => 0, 'value' => 'Jan'], ['row' => 1, 'col' => 1, 'value' => '100'], ['row' => 1, 'col' => 2, 'value' => '40'],
            ['row' => 2, 'col' => 0, 'value' => 'Feb'], ['row' => 2, 'col' => 1, 'value' => '300'], ['row' => 2, 'col' => 2, 'value' => '90'],
        ], $user->id);

        return [$user, $sheet, $tab];
    }

    private function openSales(Browser $browser, User $user, Spreadsheet $sheet): void
    {
        $browser->driver->get('about:blank');
        $browser->driver->manage()->getLog('browser');
        $browser->loginAs($user)->visit("/spreadsheets/{$sheet->id}");
        $browser->waitUsing(10, 25, fn () => $browser->driver->executeScript("return [...document.querySelectorAll('.c')].some(c => c.textContent === 'Month')"));
    }

    public function test_a_chart_is_drawn_from_a_plain_selection_and_follows_its_figures(): void
    {
        [$user, $sheet, $tab] = $this->salesSheet();

        $this->browse(function (Browser $browser) use ($user, $sheet, $tab) {
            $this->openSales($browser, $user, $sheet);
            $this->runCommand($browser, 'Charts');
            $browser->waitFor('#charts')->waitForTextIn('#charts-list', 'No charts on this sheet yet');
            $this->assertSame('charts-title', $browser->driver->executeScript('return document.activeElement.id'));

            $this->clickCell($browser, 0, 0);
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::SHIFT, WebDriverKeys::ARROW_DOWN], [WebDriverKeys::SHIFT, WebDriverKeys::ARROW_DOWN], [WebDriverKeys::SHIFT, WebDriverKeys::ARROW_RIGHT], [WebDriverKeys::SHIFT, WebDriverKeys::ARROW_RIGHT]);
            $browser->waitUsing(5, 25, fn () => $browser->value('#charts-range') === 'A1:C3');

            $browser->type('#charts-title', 'Sales and costs')->keys('#charts-title', '{enter}');
            $browser->waitForTextIn('#charts-heading', 'Sales and costs');
            $browser->assertSeeIn('#charts-list', 'Bars, A1:C3');
            $browser->waitUsing(10, 25, fn () => count($this->paint($browser)) === 2);
            $this->assertEqualsCanonicalizing([1, 2], array_keys($this->paint($browser)), 'two series, in the first two colours');

            $this->assertSame([['', 'Sales', 'Costs'], ['Jan', '100', '40'], ['Feb', '300', '90']], $this->chartTable($browser));
            $browser->assertMissing('#charts-table');
            $browser->click('#charts-figures')->waitFor('#charts-table')->assertSeeIn('#charts-figures', 'Hide the figures');

            app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 1, 'col' => 1, 'value' => '700']], $user->id);
            $this->clickCell($browser, 2, 1);
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', '555', '{enter}');
            $browser->waitUsing(10, 25, fn () => ($this->chartTable($browser)[2][1] ?? '') === '555');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_each_kind_of_chart_paints_and_a_pie_says_what_it_left_out(): void
    {
        [$user, $sheet, $tab] = $this->salesSheet();
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 3, 'col' => 0, 'value' => 'Mar'], ['row' => 3, 'col' => 1, 'value' => '-50']], $user->id);
        foreach (['bar', 'line', 'area', 'scatter'] as $type) {
            app(SheetCharts::class)->add($sheet->id, $tab->id, ucfirst($type), $type, 'A1:C4', null);
        }
        app(SheetCharts::class)->add($sheet->id, $tab->id, 'Pie', 'pie', 'A1:C4', null);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->openSales($browser, $user, $sheet);
            $this->runCommand($browser, 'Charts');
            $browser->waitForTextIn('#charts-list', 'Pie');

            foreach (['Bar', 'Line', 'Area', 'Scatter', 'Pie'] as $title) {
                $browser->driver->executeScript(<<<'JS'
                    const row = [...document.querySelectorAll('#charts-list .person')].find((r) => r.querySelector('b').textContent === arguments[0]);
                    [...row.querySelectorAll('button')].find((b) => b.textContent === 'Show').click();
                JS, [$title]);
                $browser->waitForTextIn('#charts-heading', $title);
                $browser->waitUsing(10, 25, fn () => count($this->paint($browser)) > 0);
            }

            $browser->assertSeeIn('#charts-said', 'A pie cannot show figures below nought')->assertSeeIn('#charts-said', 'A pie shows one series: Sales.');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_pie_of_money_going_out_shows_its_sizes(): void
    {
        [$user, $sheet, $tab] = $this->salesSheet();
        // Mar has no figure: a blank is nothing to warn about, on a pie of sizes.
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 1, 'col' => 1, 'value' => '-100'], ['row' => 2, 'col' => 1, 'value' => '-300'], ['row' => 3, 'col' => 0, 'value' => 'Mar']], $user->id);
        app(SheetCharts::class)->add($sheet->id, $tab->id, 'Costs', 'pie', 'A1:B4', null);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->openSales($browser, $user, $sheet);
            $this->runCommand($browser, 'Charts');
            $browser->waitForTextIn('#charts-list', 'Costs');
            $this->pressIn($browser, '#charts-list', 'Show');
            $browser->waitUsing(10, 25, fn () => count($this->paint($browser)) === 2);
            $browser->assertSeeIn('#charts-said', 'These figures are below nought, so the pie shows their sizes.')->assertDontSeeIn('#charts-said', 'left out');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_chart_with_no_figures_says_so_instead_of_drawing_nothing(): void
    {
        [$user, $sheet, $tab] = $this->salesSheet();
        app(SheetCharts::class)->add($sheet->id, $tab->id, 'Words', 'bar', 'E1:E3', null);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->openSales($browser, $user, $sheet);
            $this->runCommand($browser, 'Charts');
            $browser->waitForTextIn('#charts-list', 'Words');
            $this->pressIn($browser, '#charts-list', 'Show');
            $browser->waitForTextIn('#charts-said', 'There are no figures in these cells yet.');
            $browser->assertMissing('#charts-canvas');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_deleting_a_chart_asks_and_leaves_the_figures(): void
    {
        [$user, $sheet, $tab] = $this->salesSheet();
        app(SheetCharts::class)->add($sheet->id, $tab->id, 'Sales', 'bar', 'A1:B3', null);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->openSales($browser, $user, $sheet);
            $this->runCommand($browser, 'Charts');
            $browser->waitForTextIn('#charts-list', 'Sales');
            $this->pressIn($browser, '#charts-list', 'Show');
            $browser->waitForTextIn('#charts-heading', 'Sales');

            $this->pressIn($browser, '#charts-list', 'Delete');
            $browser->waitFor('#confirm[open]')->assertSeeIn('#confirm-text', 'The figures stay on the sheet.')->assertSeeIn('#confirm-yes', 'Delete chart');
            $browser->click('#confirm-yes');
            $browser->waitForTextIn('#charts-list', 'No charts on this sheet yet')->assertMissing('#charts-view');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_list_follows_the_sheet_in_view_and_someone_who_can_only_view_can_look(): void
    {
        [$user, $sheet, $tab] = $this->salesSheet();
        $extra = SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Stock', 'sort_order' => 2]);
        app(SheetCharts::class)->add($sheet->id, $tab->id, 'Sales', 'bar', 'A1:B3', null);
        app(SheetCharts::class)->add($sheet->id, $extra->id, 'Shelves', 'bar', 'A1:A2', null);
        $reader = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($reader, $sheet) {
            $this->openSales($browser, $reader, $sheet);
            $browser->click('#do-comments')->waitFor('#notes');
            $this->runCommand($browser, 'Charts');
            $browser->waitForTextIn('#charts-list', 'Sales')->assertMissing('#notes')->assertMissing('#charts-form')->assertDontSeeIn('#charts-list', 'Delete');
            $this->pressIn($browser, '#charts-list', 'Show');
            $browser->waitUsing(10, 25, fn () => count($this->paint($browser)) === 1);

            $browser->driver->executeScript("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Stock').click()");
            $browser->waitForTextIn('#charts-list', 'Shelves')->assertDontSeeIn('#charts-list', 'Sales')->assertMissing('#charts-view');
            $this->assertNoConsoleErrors($browser);
        });
    }

    private function pivotTable(Browser $browser): array
    {
        return $browser->driver->executeScript(<<<'JS'
            return [...document.querySelectorAll('#pivots-table tr')].map((row) => [...row.children].map((cell) => cell.textContent));
        JS);
    }

    /** @return array{0: User, 1: Spreadsheet, 2: SheetTab} */
    private function regionsSheet(): array
    {
        [$user, $sheet, $tab] = $this->sheet();
        Cell::where('sheet_tab_id', $tab->id)->delete();
        app(CellWriter::class)->write($sheet->id, $tab->id, [
            ['row' => 0, 'col' => 0, 'value' => 'Month'], ['row' => 0, 'col' => 1, 'value' => 'Region'], ['row' => 0, 'col' => 2, 'value' => 'Amount'],
            ['row' => 1, 'col' => 0, 'value' => 'Jan'], ['row' => 1, 'col' => 1, 'value' => 'East'], ['row' => 1, 'col' => 2, 'value' => '10'],
            ['row' => 2, 'col' => 0, 'value' => 'Feb'], ['row' => 2, 'col' => 1, 'value' => 'East'], ['row' => 2, 'col' => 2, 'value' => '30'],
            ['row' => 3, 'col' => 0, 'value' => 'Jan'], ['row' => 3, 'col' => 1, 'value' => 'West'], ['row' => 3, 'col' => 2, 'value' => '100'],
        ], $user->id);

        return [$user, $sheet, $tab];
    }

    public function test_a_pivot_is_written_as_a_sentence_from_the_tables_own_headings(): void
    {
        [$user, $sheet] = $this->regionsSheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->openSales($browser, $user, $sheet);
            $this->runCommand($browser, 'Pivot tables');
            $browser->waitFor('#pivots')->waitForTextIn('#pivots-list', 'No pivot tables on this sheet yet');
            $browser->assertDisabled('#pivots-form button');
            $browser->waitFor('#pivots-hint')->assertDisabled('#pivots-form button');

            $browser->driver->executeScript(<<<'JS'
                const box = document.getElementById('pivots-range');
                box.value = 'A1:C4';
                box.dispatchEvent(new Event('input', { bubbles: true }));
            JS);
            $browser->waitUsing(5, 25, fn () => $browser->value('#pivots-value') === 'C');
            $browser->assertMissing('#pivots-hint');
            $this->assertSame('A', $browser->value('#pivots-row'), 'words to group by');
            $this->assertSame(['Month', 'Region', 'Amount'], $browser->driver->executeScript("return [...document.querySelectorAll('#pivots-value option')].map(o => o.textContent)"));

            $browser->select('#pivots-row', 'B')->press('Add');
            $browser->waitForTextIn('#pivots-heading', 'Sum of Amount by Region');
            $browser->waitUsing(5, 25, fn () => count($this->pivotTable($browser)) === 4);
            $this->assertSame([['Region', 'Sum of Amount'], ['East', '40'], ['West', '100'], ['Total', '140']], $this->pivotTable($browser));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_form_groups_by_words_and_adds_up_figures_wherever_they_sit(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        Cell::where('sheet_tab_id', $tab->id)->delete();
        app(CellWriter::class)->write($sheet->id, $tab->id, [
            ['row' => 0, 'col' => 0, 'value' => 'Amount'], ['row' => 0, 'col' => 1, 'value' => 'Region'],
            ['row' => 1, 'col' => 0, 'value' => '10'], ['row' => 1, 'col' => 1, 'value' => 'East'],
        ], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $browser->driver->get('about:blank');
            $browser->driver->manage()->getLog('browser');
            $browser->loginAs($user)->visit("/spreadsheets/{$sheet->id}")->waitFor('.c');
            $this->runCommand($browser, 'Pivot tables');
            $browser->waitFor('#pivots');
            $browser->driver->executeScript(<<<'JS'
                const box = document.getElementById('pivots-range');
                box.value = 'A1:B2';
                box.dispatchEvent(new Event('input', { bubbles: true }));
            JS);
            $browser->waitUsing(5, 25, fn () => $browser->value('#pivots-row') === 'B');
            $this->assertSame('A', $browser->value('#pivots-value'));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_an_average_names_its_total_and_empties_stay_empty(): void
    {
        [$user, $sheet, $tab] = $this->regionsSheet();
        app(PivotTableService::class)->add($sheet->id, $tab->id, 'Average by region', 'A1:C4', 'B', 'A', 'C', 'average');

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->openSales($browser, $user, $sheet);
            $this->runCommand($browser, 'Pivot tables');
            $browser->waitForTextIn('#pivots-list', 'Average by region');
            $this->pressIn($browser, '#pivots-list', 'Show');
            $browser->waitUsing(5, 25, fn () => count($this->pivotTable($browser)) === 4);
            $this->assertSame([
                ['Region', 'Feb', 'Jan', 'Overall'],
                ['East', '30', '10', '20'],
                ['West', '', '100', '100'],
                ['Overall', '30', '55', '46.67'],
            ], $this->pivotTable($browser));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_pivot_follows_its_figures_and_says_what_it_left_out(): void
    {
        [$user, $sheet, $tab] = $this->regionsSheet();
        app(PivotTableService::class)->add($sheet->id, $tab->id, 'Sales', 'A1:C5', 'B', null, 'C', 'sum');

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->openSales($browser, $user, $sheet);
            $this->runCommand($browser, 'Pivot tables');
            $browser->waitForTextIn('#pivots-list', 'Sales');
            $this->pressIn($browser, '#pivots-list', 'Show');
            $browser->waitUsing(5, 25, fn () => ($this->pivotTable($browser)[3][1] ?? '') === '140');

            $this->clickCell($browser, 3, 2);
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            // A total in the years is still a total: it groups its thousands.
            $browser->keys('#sheet', '1900', '{enter}');
            $browser->waitUsing(10, 25, fn () => ($this->pivotTable($browser)[3][1] ?? '') === '1 940');

            $this->clickCell($browser, 4, 1);
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', 'East', '{enter}');
            $this->clickCell($browser, 4, 2);
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', 'n/a', '{enter}');
            $browser->waitForTextIn('#pivots-said', '1 cell holds words, so it is left out.', 5);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_table_with_nothing_under_its_headings_says_so(): void
    {
        [$user, $sheet, $tab] = $this->regionsSheet();
        app(PivotTableService::class)->add($sheet->id, $tab->id, 'Empty', 'E1:F3', 'E', null, 'F', 'sum');

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->openSales($browser, $user, $sheet);
            $this->runCommand($browser, 'Pivot tables');
            $browser->waitForTextIn('#pivots-list', 'Empty');
            $this->pressIn($browser, '#pivots-list', 'Show');
            $browser->waitForTextIn('#pivots-said', 'There is nothing under the headings yet.')->assertMissing('#pivots-table');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_deleting_a_pivot_asks_and_the_list_follows_the_sheet_in_view(): void
    {
        [$user, $sheet, $tab] = $this->regionsSheet();
        $extra = SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Stock', 'sort_order' => 2]);
        app(PivotTableService::class)->add($sheet->id, $tab->id, 'Sales', 'A1:C4', 'B', null, 'C', 'sum');
        app(PivotTableService::class)->add($sheet->id, $extra->id, 'Shelves', 'A1:C4', 'B', null, 'C', 'sum');

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->openSales($browser, $user, $sheet);
            $browser->click('#do-comments')->waitFor('#notes');
            $this->runCommand($browser, 'Pivot tables');
            $browser->waitForTextIn('#pivots-list', 'Sales')->assertMissing('#notes');
            $this->pressIn($browser, '#pivots-list', 'Show');
            $browser->waitForTextIn('#pivots-heading', 'Sales');

            $browser->driver->executeScript("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Stock').click()");
            $browser->waitForTextIn('#pivots-list', 'Shelves')->assertDontSeeIn('#pivots-list', 'Sales')->assertMissing('#pivots-view');

            $this->pressIn($browser, '#pivots-list', 'Delete');
            $browser->waitFor('#confirm[open]')->assertSeeIn('#confirm-text', 'The figures stay on the sheet.')->assertSeeIn('#confirm-yes', 'Delete pivot table');
            $browser->click('#confirm-yes');
            $browser->waitForTextIn('#pivots-list', 'No pivot tables on this sheet yet');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_view_can_read_a_pivot_and_nothing_more(): void
    {
        [$user, $sheet, $tab] = $this->regionsSheet();
        app(PivotTableService::class)->add($sheet->id, $tab->id, 'Sales', 'A1:C4', 'B', null, 'C', 'sum');
        $reader = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($reader, $sheet) {
            $this->openSales($browser, $reader, $sheet);
            $this->runCommand($browser, 'Pivot tables');
            $browser->waitForTextIn('#pivots-list', 'Sales')->assertMissing('#pivots-form')->assertDontSeeIn('#pivots-list', 'Delete');
            $this->pressIn($browser, '#pivots-list', 'Show');
            $browser->waitUsing(5, 25, fn () => count($this->pivotTable($browser)) === 4);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_macro_records_typing_plays_as_one_step_and_undo_takes_it_back(): void
    {
        [$user, $sheet, $tab] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Macros');
            $browser->waitFor('#macros')->waitForTextIn('#macros-list', 'No macros in this spreadsheet yet');
            $browser->assertMissing('#macros-stop');

            $browser->type('#macros-name', 'Fill in')->click('#macros-record');
            $browser->waitFor('#macros-stop')->assertSeeIn('#macros-state', 'Recording a macro: 0 edits.')->assertSeeIn('#note', 'Recording a macro');

            $this->clickCell($browser, 4, 0);
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', 'First', '{enter}');
            $browser->waitForTextIn('#macros-state', '1 edit.');
            $this->clickCell($browser, 4, 1);
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', 'Second', '{enter}');
            $browser->waitForTextIn('#macros-state', '2 edits.');
            $this->clickCell($browser, 4, 1);
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', 'Second again', '{enter}');
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 4, 1) === 'Second again');
            $browser->assertSeeIn('#note', 'Recording a macro: 2 edits.');

            $browser->click('#macros-stop');
            $browser->waitForTextIn('#macros-list', 'Fill in');
            $browser->assertSeeIn('#macros-list', '2 edits')->assertSeeIn('#note', 'Saved the macro: 2 edits.')->assertVisible('#macros-record');

            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 4, 1) === 'Second');
            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z'], [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 4, 0) === '');

            $this->pressIn($browser, '#macros-list', 'Play');
            $browser->waitForTextIn('#note', 'Played Fill in: 2 cells. Undo takes it back.');
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 4, 1) === 'Second again');
            $this->assertSame('First', $this->cell($browser, 4, 0));

            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 4, 0) === '' && $this->cell($browser, 4, 1) === '');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_playing_is_not_recorded_and_a_recording_can_be_dropped(): void
    {
        [$user, $sheet] = $this->sheet();
        app(SheetMacros::class)->add($sheet->id, 'Stored', [['type' => 'set', 'row' => 5, 'col' => 0, 'value' => 'Played']]);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Macros');
            $browser->waitForTextIn('#macros-list', 'Stored');
            $browser->click('#macros-record')->waitFor('#macros-stop');

            $this->pressIn($browser, '#macros-list', 'Play');
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 5, 0) === 'Played');
            $browser->assertSeeIn('#macros-state', 'Recording a macro: 0 edits.');

            $browser->click('#macros-discard')->waitUntilMissing('#macros-stop');
            $browser->assertSeeIn('#note', 'Discarded the recording.')->assertVisible('#macros-record');
            $this->assertCount(1, app(SheetMacros::class)->of($sheet->fresh()));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_recording_with_nothing_in_it_saves_nothing_and_a_macro_can_be_deleted(): void
    {
        [$user, $sheet] = $this->sheet();
        app(SheetMacros::class)->add($sheet->id, 'Stored', [['type' => 'set', 'row' => 5, 'col' => 0, 'value' => 'x']]);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Macros');
            $browser->waitForTextIn('#macros-list', 'Stored');
            $browser->click('#macros-record')->waitFor('#macros-stop')->click('#macros-stop');
            $browser->waitForTextIn('#note', 'Nothing was recorded.');
            $this->assertCount(1, app(SheetMacros::class)->of($sheet->fresh()));

            $this->pressIn($browser, '#macros-list', 'Delete');
            $browser->waitFor('#confirm[open]')->assertSeeIn('#confirm-yes', 'Delete macro')->click('#confirm-yes');
            $browser->waitForTextIn('#macros-list', 'No macros in this spreadsheet yet');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_rule_can_refuse_part_of_a_macro_and_it_says_so(): void
    {
        [$user, $sheet, $tab] = $this->sheet();
        SheetTab::updateLayout($tab->id, fn (array $layout) => [...$layout, 'validation_rules' => [['col' => 0, 'row' => 6, 'type' => 'number', 'min' => 0, 'max' => 10]]]);
        app(SheetMacros::class)->add($sheet->id, 'Stored', [['type' => 'set', 'row' => 5, 'col' => 0, 'value' => 'ok'], ['type' => 'set', 'row' => 6, 'col' => 0, 'value' => 'not a number']]);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Macros');
            $browser->waitForTextIn('#macros-list', 'Stored');
            $this->pressIn($browser, '#macros-list', 'Play');
            $browser->waitForTextIn('#note', 'Played Stored: 1 cell, 1 refused by rules on them.');
            $this->assertSame('ok', $this->cell($browser, 5, 0));
            $this->assertSame('', $this->cell($browser, 6, 0));
        });
    }

    public function test_scripts_say_they_are_off(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'scripts');
            $browser->waitForTextIn('#found', 'Not switched on')->assertDontSeeIn('#found', 'In the classic editor');
            $browser->keys('#find', WebDriverKeys::ENTER);
            $browser->waitForTextIn('#note', 'Scripts are turned off on this server.');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_view_has_no_macros(): void
    {
        [, $sheet] = $this->sheet();
        $reader = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($reader, $sheet) {
            $this->open($browser, $reader, $sheet);
            $browser->assertMissing('#macros');
            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'macros');
            $browser->pause(100)->assertDontSeeIn('#found', 'Macros');
            $this->assertNoConsoleErrors($browser);
        });
    }

    /** @return array{0: User, 1: Spreadsheet, 2: SheetTab, 3: SheetTab} */
    private function threeSheets(): array
    {
        [$user, $sheet, $tab] = $this->sheet();
        $stock = SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Stock', 'sort_order' => 2]);
        $notes = SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Notes', 'sort_order' => 3]);
        app(CellWriter::class)->write($sheet->id, $stock->id, [['row' => 0, 'col' => 0, 'value' => 'Shelves']], $user->id);

        return [$user, $sheet, $stock, $notes];
    }

    private function tabNames(Browser $browser): array
    {
        return $browser->driver->executeScript("return [...document.querySelectorAll('#tabs [data-tab]')].map(b => b.textContent)");
    }

    /** Drag a tab over another with the page's own pointer events, ending in the click a browser sends. */
    private function dragTab(Browser $browser, string $name, string $over, string $pointer = 'mouse'): void
    {
        $browser->driver->executeScript(<<<'JS'
            const tab = (text) => [...document.querySelectorAll('#tabs [data-tab]')].find(b => b.textContent === text);
            const from = tab(arguments[0]).getBoundingClientRect();
            const to = tab(arguments[1]).getBoundingClientRect();
            const y = from.top + 4;
            const held = tab(arguments[0]);
            held.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: from.left + 4, clientY: y, button: 0, pointerType: arguments[2] }));
            window.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, clientX: to.left + 2, clientY: y }));
            window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, clientX: to.left + 2, clientY: y }));
            held.click();
        JS, [$name, $over, $pointer]);
    }

    public function test_a_sheet_is_dragged_along_the_foot_and_undo_puts_it_back(): void
    {
        [$user, $sheet] = $this->threeSheets();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->assertSame(['Sheet1', 'Stock', 'Notes'], $this->tabNames($browser));

            $this->dragTab($browser, 'Stock', 'Sheet1', 'touch');
            $browser->pause(150)->assertSeeIn('#tabs [aria-current]', 'Stock');
            $this->assertSame(['Sheet1', 'Stock', 'Notes'], $this->tabNames($browser), 'on touch the foot scrolls instead');
            $browser->driver->executeScript("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Sheet1').click()");
            $browser->waitForTextIn('#tabs [aria-current]', 'Sheet1');

            $this->dragTab($browser, 'Notes', 'Sheet1');
            $browser->waitUsing(5, 25, fn () => $this->tabNames($browser) === ['Notes', 'Sheet1', 'Stock']);
            $browser->waitUsing(5, 25, fn () => SheetTab::where('spreadsheet_id', $sheet->id)->orderBy('sort_order')->pluck('name')->all() === ['Notes', 'Sheet1', 'Stock']);
            $browser->pause(100)->assertSeeIn('#tabs [aria-current]', 'Sheet1');

            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(5, 25, fn () => $this->tabNames($browser) === ['Sheet1', 'Stock', 'Notes']);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_hidden_sheet_keeps_its_place_when_the_others_move(): void
    {
        [$user, $sheet, $stock] = $this->threeSheets();
        $stock->update(['hidden' => true]);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->assertSame(['Sheet1', 'Notes'], $this->tabNames($browser));

            $this->dragTab($browser, 'Notes', 'Sheet1');
            $browser->waitUsing(5, 25, fn () => SheetTab::where('spreadsheet_id', $sheet->id)->orderBy('sort_order')->pluck('name')->all() === ['Notes', 'Stock', 'Sheet1']);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_move_the_workbook_has_outgrown_is_refused_and_the_tab_goes_back(): void
    {
        [$user, $sheet] = $this->threeSheets();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => 'Added elsewhere', 'sort_order' => 4]);

            $this->dragTab($browser, 'Notes', 'Sheet1');
            $browser->waitForTextIn('#note', 'The sheets changed while you were moving this one');
            $this->assertSame(['Sheet1', 'Stock', 'Notes'], $this->tabNames($browser));
        });
    }

    public function test_the_palette_moves_a_sheet_and_gives_it_a_colour(): void
    {
        [$user, $sheet] = $this->threeSheets();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Move sheet left');
            $browser->waitForTextIn('#note', 'This sheet is already first.');

            $this->runCommand($browser, 'Move sheet right');
            $browser->waitUsing(5, 25, fn () => $this->tabNames($browser) === ['Stock', 'Sheet1', 'Notes']);

            $this->runCommand($browser, 'Sheet colour: Teal');
            $browser->waitFor('#tabs .sheet-dot.teal');
            $this->assertSame('rgb(14, 138, 160)', $browser->driver->executeScript("return getComputedStyle(document.querySelector('#tabs .sheet-dot')).backgroundColor"));

            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUntilMissing('#tabs .sheet-dot');
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'y']);
            $browser->waitFor('#tabs .sheet-dot.teal');

            $this->runCommand($browser, 'No sheet colour');
            $browser->waitUntilMissing('#tabs .sheet-dot');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_sheet_is_hidden_and_the_palette_brings_it_back(): void
    {
        [$user, $sheet, $stock] = $this->threeSheets();

        $this->browse(function (Browser $browser) use ($user, $sheet, $stock) {
            $this->open($browser, $user, $sheet);
            $browser->driver->executeScript("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Stock').click()");
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 0, 0) === 'Shelves');

            $this->runCommand($browser, 'Hide this sheet');
            $browser->waitUsing(5, 25, fn () => $this->tabNames($browser) === ['Sheet1', 'Notes']);
            $browser->waitForTextIn('#note', 'Hid Stock.');
            $browser->waitForTextIn('#tabs [aria-current]', 'Sheet1');
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 0, 0) === 'Sales');
            $this->assertTrue($stock->fresh()->hidden);

            $browser->driver->executeScript("document.getElementById('sheet').focus()");
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'z']);
            $browser->waitUsing(5, 25, fn () => $this->tabNames($browser) === ['Sheet1', 'Stock', 'Notes']);
            $browser->waitForTextIn('#tabs [aria-current]', 'Sheet1');
            $browser->keys('#sheet', [WebDriverKeys::CONTROL, 'y']);
            $browser->waitUsing(5, 25, fn () => $this->tabNames($browser) === ['Sheet1', 'Notes']);

            $this->runCommand($browser, 'Show sheet: Stock');
            $browser->waitUsing(5, 25, fn () => $this->tabNames($browser) === ['Sheet1', 'Stock', 'Notes']);
            $browser->waitForTextIn('#tabs [aria-current]', 'Stock');
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 0, 0) === 'Shelves');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_page_opens_on_the_first_sheet_showing_and_a_link_can_reach_a_hidden_one(): void
    {
        [$user, $sheet, $stock, $notes] = $this->threeSheets();
        $sheet->sheetTabs()->where('name', 'Sheet1')->update(['hidden' => true]);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $browser->driver->get('about:blank');
            $browser->driver->manage()->getLog('browser');
            $browser->loginAs($user)->visit("/spreadsheets/{$sheet->id}");
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 0) === 'Shelves');
            $this->assertSame(['Stock', 'Notes'], $this->tabNames($browser));

            $hidden = SheetTab::where('spreadsheet_id', $sheet->id)->where('name', 'Sheet1')->value('id');
            $browser->visit("/spreadsheets/{$sheet->id}?tab={$hidden}");
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 0) === 'Sales');
            $this->assertSame(['Sheet1', 'Stock', 'Notes'], $this->tabNames($browser), 'it shows while someone is in it');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_last_sheet_showing_cannot_be_hidden(): void
    {
        [$user, $sheet] = $this->sheet();

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $this->runCommand($browser, 'Hide this sheet');
            $browser->waitForTextIn('#note', 'keeps at least one sheet showing');
            $this->assertSame(['Sheet1'], $this->tabNames($browser));
        });
    }

    public function test_someone_who_can_only_view_cannot_keep_sheets(): void
    {
        [$user, $sheet] = $this->threeSheets();
        $reader = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($reader, $sheet) {
            $this->open($browser, $reader, $sheet);
            $this->dragTab($browser, 'Notes', 'Sheet1');
            $browser->pause(150);
            $this->assertSame(['Sheet1', 'Stock', 'Notes'], $this->tabNames($browser));
            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'sheet');
            $browser->pause(100)->assertDontSeeIn('#found', 'Hide this sheet')->assertDontSeeIn('#found', 'Sheet colour');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_sheet_is_duplicated_beside_the_original_and_goes_its_own_way(): void
    {
        [$user, $sheet, $stock] = $this->threeSheets();
        app(CellWriter::class)->write($sheet->id, $stock->id, [['row' => 1, 'col' => 0, 'value' => '4'], ['row' => 2, 'col' => 0, 'value' => '=A2*2']], $user->id);
        $stock->update(['formulas_built_at' => null]);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $this->open($browser, $user, $sheet);
            $browser->driver->executeScript("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Stock').click()");
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 0, 0) === 'Shelves');

            $this->runCommand($browser, 'Duplicate this sheet');
            $browser->waitForTextIn('#note', 'Made Stock copy.');
            $this->assertSame(['Sheet1', 'Stock', 'Stock copy', 'Notes'], $this->tabNames($browser));
            $browser->waitForTextIn('#tabs [aria-current]', 'Stock copy');
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 2, 0) === '8');

            $this->clickCell($browser, 1, 0);
            $browser->driver->getKeyboard()->sendKeys(['9', WebDriverKeys::ENTER]);
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 2, 0) === '18');

            $browser->driver->executeScript("[...document.querySelectorAll('#tabs button')].find(b => b.textContent === 'Stock').click()");
            $browser->waitForTextIn('#tabs [aria-current]', 'Stock');
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 2, 0) === '8');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_someone_who_can_only_read_duplicates_a_workbook_and_can_edit_their_copy(): void
    {
        [, $sheet] = $this->threeSheets();
        $reader = User::factory()->withPersonalTeam()->create();
        $sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->browse(function (Browser $browser) use ($reader, $sheet) {
            $this->open($browser, $reader, $sheet);
            $this->runCommand($browser, 'Duplicate this workbook');
            $browser->waitUsing(10, 50, fn () => ! str_ends_with($browser->driver->getCurrentURL(), "/spreadsheets/{$sheet->id}"));

            $copy = Spreadsheet::where('owner_id', $reader->id)->sole();
            $this->assertStringEndsWith("/spreadsheets/{$copy->id}", $browser->driver->getCurrentURL());
            $browser->waitUsing(10, 25, fn () => $this->cell($browser, 0, 0) === 'Sales');
            $this->assertSame(['Sheet1', 'Stock', 'Notes'], $this->tabNames($browser));

            // The page's arrival is a view transition, and nothing takes a click until it ends.
            $browser->pause(500);
            $this->clickCell($browser, 4, 0);
            $browser->driver->getKeyboard()->sendKeys(['mine', WebDriverKeys::ENTER]);
            $browser->waitUsing(5, 25, fn () => $this->cell($browser, 4, 0) === 'mine');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_dashboard_duplicates_the_workbook_it_is_showing(): void
    {
        [$user, $sheet] = $this->threeSheets();
        Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $user->id, 'name' => 'Another one']);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $browser->loginAs($user)->visit('/dashboard');
            $browser->waitFor('#stage-duplicate');

            // The page opens on the first row, so the second proves the button follows the choice.
            $name = $browser->driver->executeScript(<<<'JS'
                const row = document.querySelectorAll('.ledger tbody tr')[1];
                row.dispatchEvent(new PointerEvent('pointerover', { bubbles: true, pointerType: 'mouse' }));
                return row.querySelector('a').textContent.trim();
            JS);
            $this->assertContains($name, [$sheet->name, 'Another one']);
            $browser->waitForTextIn('#stage-title', $name);
            $browser->click('#stage-duplicate button');
            $browser->waitUsing(10, 50, fn () => Spreadsheet::where('name', $name.' copy')->exists());

            $copy = Spreadsheet::where('name', $name.' copy')->sole();
            $browser->waitUsing(10, 50, fn () => str_ends_with($browser->driver->getCurrentURL(), "/spreadsheets/{$copy->id}"));
        });
    }
}
