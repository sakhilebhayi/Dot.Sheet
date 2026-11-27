<?php

namespace Tests\Browser;

use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * axe-core (WCAG 2.2 A and AA rules) over the pages people work in. EA-5 (A-02): axe-core is a pinned dev
 * dependency, this runs in CI with the other browser tests, and it FAILS on any serious or critical finding.
 * Moderate and minor findings are printed and kept in storage/logs/axe.txt. Automated rules are not the whole
 * of accessibility: keyboard and screen-reader passes are separate.
 */
class AccessibilityAuditTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function audit(Browser $browser, string $page): array
    {
        $browser->driver->executeScript((string) file_get_contents(self::axePath()));
        $found = $browser->driver->executeAsyncScript(<<<'JS'
            const done = arguments[0];
            axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] }).then((r) => done(r.violations.map((v) => ({
                id: v.id, impact: v.impact, count: v.nodes.length, first: v.nodes[0].target.join(' '), why: v.nodes[0].failureSummary.slice(0, 160),
            }))));
        JS);

        return array_map(fn (array $v) => ['page' => $page, ...$v], $found);
    }

    private static function axePath(): string
    {
        return (string) (env('AXE_PATH') ?: base_path('node_modules/axe-core/axe.min.js'));
    }

    public function test_audit(): void
    {
        $this->assertFileExists(self::axePath(), 'axe-core is a dev dependency: run npm ci');

        $user = User::factory()->withPersonalTeam()->create();
        $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'team_id' => $user->currentTeam->id, 'name' => 'Budget']);
        $tab = $sheet->sheetTabs()->firstOrFail();
        app(CellWriter::class)->write($sheet->id, $tab->id, [['row' => 0, 'col' => 0, 'value' => 'Sales'], ['row' => 0, 'col' => 1, 'value' => '10'], ['row' => 1, 'col' => 1, 'value' => '=B1*2']], $user->id);

        $this->browse(function (Browser $browser) use ($user, $sheet) {
            $found = [];
            $browser->visit('/')->pause(500);
            $found = [...$found, ...$this->audit($browser, 'welcome')];
            $browser->visit('/login')->pause(500);
            $found = [...$found, ...$this->audit($browser, 'login')];

            $browser->loginAs($user);
            foreach (['/dashboard', '/spreadsheets/create', '/integrations', '/activity', '/user/profile', '/teams/'.$user->currentTeam->id, "/spreadsheets/{$sheet->id}/classic"] as $path) {
                $browser->visit($path)->pause(500);
                $found = [...$found, ...$this->audit($browser, $path)];
            }

            $browser->visit("/spreadsheets/{$sheet->id}")->waitForText('Sales')->pause(500);
            $found = [...$found, ...$this->audit($browser, 'editor')];
            foreach (['comments' => '#do-comments', 'share' => '#do-share', 'palette' => '#open-palette', 'format' => '#do-more', 'bell' => '[data-dh-bell] button'] as $name => $button) {
                $browser->script("document.querySelector('{$button}')?.click()");
                $browser->pause(400);
                $found = [...$found, ...$this->audit($browser, "editor {$name}")];
                $browser->driver->getKeyboard()->sendKeys("\u{E00C}");
                $browser->pause(200);
            }

            $lines = array_map(fn (array $f) => $f['page'].': '.json_encode(array_diff_key($f, ['page' => 1])), $found);
            fwrite(STDERR, "\nAXE ".count($found)." findings\n".implode("\n", $lines)."\n");
            file_put_contents(storage_path('logs/axe.txt'), implode("\n", $lines)."\n");

            $blocking = array_values(array_filter($lines, fn (string $line, int $i) => in_array($found[$i]['impact'], ['serious', 'critical'], true), ARRAY_FILTER_USE_BOTH));
            $this->assertSame([], $blocking, 'Serious or critical accessibility findings');
        });
    }
}
