<?php

namespace Tests\Browser;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
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

        return $browser->loginAs($user)->visit("/spreadsheets/{$sheet->id}/grid")
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
            $browser->visit("/spreadsheets/{$sheet->id}/grid");
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
}
