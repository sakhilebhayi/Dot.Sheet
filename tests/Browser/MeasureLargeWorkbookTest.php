<?php

namespace Tests\Browser;

use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Real numbers from a real browser and server, for the readiness report. Off by default because it takes minutes:
 *
 *   MEASURE_ROWS=10000 php artisan dusk tests/Browser/MeasureLargeWorkbookTest.php
 *
 * Ten columns: a date, text, two figures, a formula on each row and text after it, with a total at the bottom.
 */
class MeasureLargeWorkbookTest extends DuskTestCase
{
    use DatabaseTruncation;

    private const COLUMNS = 10;

    public function test_measure(): void
    {
        $rows = (int) env('MEASURE_ROWS', 0);
        if ($rows === 0) {
            $this->markTestSkipped('Set MEASURE_ROWS to measure.');
        }

        $user = User::factory()->withPersonalTeam()->create();
        $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'team_id' => $user->currentTeam->id, 'name' => 'Large']);
        $tab = $sheet->sheetTabs()->firstOrFail();
        $this->fill($sheet->id, $tab->id, $rows);

        $this->browse(function (Browser $browser) use ($user, $sheet, $rows) {
            $browser->loginAs($user)->visit('/dashboard');
            $started = microtime(true);
            $browser->visit("/spreadsheets/{$sheet->id}");
            $browser->waitUsing(120, 50, fn () => $browser->driver->executeScript("return [...document.querySelectorAll('.c')].some(c => c.textContent === 'Row 1')"));
            $open = microtime(true) - $started;

            $api = $browser->driver->executeScript(<<<'JS'
                const of = (part) => performance.getEntriesByType('resource').filter((e) => e.name.includes(part)).map((e) => Math.round(e.duration));
                return { sheet: of('/sheet'), cells: of('/cells') };
            JS);

            $fps = $browser->driver->executeAsyncScript(<<<'JS'
                const done = arguments[0];
                const box = document.getElementById('sheet');
                const scroller = [box, ...box.querySelectorAll('*')].find((e) => e.scrollHeight > e.clientHeight + 100 && getComputedStyle(e).overflowY !== 'visible') ?? document.scrollingElement;
                const from = scroller.scrollTop;
                const started = performance.now();
                let frames = 0;
                let longest = 0;
                let last = started;
                const step = (now) => {
                    frames++;
                    longest = Math.max(longest, now - last);
                    last = now;
                    scroller.scrollTop += 120;
                    if (now - started < 3000) requestAnimationFrame(step);
                    else done({ fps: Math.round(frames / ((now - started) / 1000)), longest_frame_ms: Math.round(longest), scrolled_px: Math.round(scroller.scrollTop - from) });
                };
                requestAnimationFrame(step);
            JS);

            $save = $browser->driver->executeAsyncScript(<<<'JS'
                const [spreadsheet, done] = arguments;
                const token = document.querySelector('meta[name="csrf-token"]').content;
                const boot = JSON.parse(document.getElementById('boot').textContent);
                const tab = boot.tab ?? boot.tabs[0].id;
                const post = (body) => fetch(`/spreadsheets/${spreadsheet}/tabs/${tab}/cells`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify(body) });
                const timed = async (body) => { const at = performance.now(); const r = await post(body); await r.text(); return { status: r.status, ms: Math.round(performance.now() - at) }; };
                (async () => {
                    const version = boot.version ?? 0;
                    const plain = await timed({ version, edits: [{ row: 1, col: 9, value: 'note' }] });
                    const feeding = await timed({ version, edits: [{ row: 1, col: 2, value: '7' }] });
                    const at = performance.now();
                    const r = await fetch(`/spreadsheets/${spreadsheet}/export/workbook/xlsx`);
                    const size = (await r.blob()).size;
                    done({ plain, feeding_total: feeding, export_xlsx: { status: r.status, ms: Math.round(performance.now() - at), bytes: size } });
                })();
            JS, [$sheet->id]);

            $result = ['rows' => $rows, 'cells' => $rows * self::COLUMNS, 'open_seconds' => round($open, 2), 'api_ms' => $api, 'scroll' => $fps, 'writes' => $save];
            fwrite(STDERR, "\nMEASURE ".json_encode($result)."\n");
            file_put_contents(storage_path('logs/measure.jsonl'), json_encode($result)."\n", FILE_APPEND);
        });

        $this->assertTrue(true);
    }

    private function fill(int $spreadsheetId, int $tabId, int $rows): void
    {
        $now = now();
        $batch = [];
        $add = function (int $r, int $c, string $raw, ?string $computed, ?string $formula) use ($spreadsheetId, $tabId, $now, &$batch) {
            $batch[] = ['spreadsheet_id' => $spreadsheetId, 'sheet_tab_id' => $tabId, 'row_index' => $r, 'col_index' => $c, 'raw_value' => $raw, 'computed_value' => $computed, 'formula' => $formula, 'updated_at' => $now];
            if (count($batch) >= 500) {
                DB::table('cells')->insert($batch);
                $batch = [];
            }
        };

        for ($r = 0; $r < $rows; $r++) {
            $n = $r + 1;
            $add($r, 0, 'Row '.$n, 'Row '.$n, null);
            $add($r, 1, 'Cape Town', 'Cape Town', null);
            $add($r, 2, (string) ($n % 97), (string) ($n % 97), null);
            $add($r, 3, (string) ($n % 13), (string) ($n % 13), null);
            $add($r, 4, "=C{$n}*D{$n}", (string) (($n % 97) * ($n % 13)), "=C{$n}*D{$n}");
            foreach ([5, 6, 7, 8, 9] as $c) {
                $add($r, $c, "t{$c}", "t{$c}", null);
            }
        }
        $add($rows, 4, '=SUM(E1:E'.$rows.')', null, '=SUM(E1:E'.$rows.')');
        DB::table('cells')->insert($batch);
    }
}
