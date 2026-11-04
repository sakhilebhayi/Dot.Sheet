<?php

namespace Tests\Browser;

use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
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
            $browser->click('#open-palette')->waitFor('#find')->keys('#find', 'share');
            $browser->waitForTextIn('#found', 'Share this sheet')->assertSeeIn('#found', 'In the classic editor');

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
}
