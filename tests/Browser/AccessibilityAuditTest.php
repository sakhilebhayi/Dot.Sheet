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
 * axe-core over the pages people work in, for the readiness report. axe is not a dependency, so this is off by default:
 *
 *   AXE_PATH=/path/to/axe.min.js php artisan dusk tests/Browser/AccessibilityAuditTest.php
 */
class AccessibilityAuditTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function audit(Browser $browser, string $page): array
    {
        $browser->driver->executeScript((string) file_get_contents((string) env('AXE_PATH')));
        $found = $browser->driver->executeAsyncScript(<<<'JS'
            const done = arguments[0];
            axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] }).then((r) => done(r.violations.map((v) => ({
                id: v.id, impact: v.impact, count: v.nodes.length, first: v.nodes[0].target.join(' '), why: v.nodes[0].failureSummary.slice(0, 160),
            }))));
        JS);

        return array_map(fn (array $v) => $page.': '.json_encode($v), $found);
    }

    public function test_audit(): void
    {
        if (! env('AXE_PATH')) {
            $this->markTestSkipped('Set AXE_PATH to run.');
        }

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
            foreach (['/dashboard', '/spreadsheets/create', '/integrations', '/activity', '/user/profile', '/teams/'.$user->currentTeam->id] as $path) {
                $browser->visit($path)->pause(500);
                $found = [...$found, ...$this->audit($browser, $path)];
            }

            $browser->visit("/spreadsheets/{$sheet->id}")->waitForText('Sales')->pause(500);
            $found = [...$found, ...$this->audit($browser, 'editor')];
            foreach (['notes-open' => '#do-notes', 'share' => '#do-share', 'palette' => '#open-palette'] as $name => $button) {
                $browser->script("document.querySelector('{$button}')?.click()");
                $browser->pause(400);
                $found = [...$found, ...$this->audit($browser, "editor {$name}")];
                $browser->driver->getKeyboard()->sendKeys("\u{E00C}");
                $browser->pause(200);
            }

            fwrite(STDERR, "\nAXE ".count($found)." findings\n".implode("\n", $found)."\n");
            file_put_contents(storage_path('logs/axe.txt'), implode("\n", $found)."\n");
            $this->assertIsArray($found);
        });
    }
}
