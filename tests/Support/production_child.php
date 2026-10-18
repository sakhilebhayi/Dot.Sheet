<?php

/**
 * Helper for tests that need a real second process on a real SQLite file with the production
 * settings (WAL, BEGIN IMMEDIATE, busy_timeout 5000, CACHE_STORE=database), which phpunit's
 * in-memory database and array cache cannot give. Not a test: run by the tests with proc_open.
 *
 *   php production_child.php setup                       migrate, create a spreadsheet; prints "spreadsheetId tabId"
 *   php production_child.php scenario <name> <ssId> <tab> seed one measured scenario, run an insert, print JSON
 *   php production_child.php bump <n> <ssId> <tab>       n row inserts through SheetStructureService
 *   php production_child.php settings <n> <ssId>         n settings writes; exits 1 if the version ever went backwards
 */

use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SheetStructureService;
use App\Services\StructureVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require dirname(__DIR__, 2).'/vendor/autoload.php';

foreach (['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'LOG_CHANNEL' => 'null', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv("$key=".(getenv($key) !== false ? getenv($key) : $value));
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1];

if ($mode === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    $user = User::factory()->create();
    $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'name' => 'child']);
    echo $sheet->id.' '.$sheet->defaultSheetTab()->id;
    exit(0);
}

if ($mode === 'bump') {
    [$n, $sheetId, $tabId] = [(int) $argv[2], (int) $argv[3], (int) $argv[4]];
    $service = app(SheetStructureService::class);
    for ($i = 0; $i < $n; $i++) {
        $service->insertRow($sheetId, $tabId, 0);
    }
    echo StructureVersion::current($sheetId);
    exit(0);
}

if ($mode === 'settings') {
    [$n, $sheetId] = [(int) $argv[2], (int) $argv[3]];
    $last = 0;
    for ($i = 0; $i < $n; $i++) {
        Spreadsheet::updateSettings($sheetId, function (array $settings) use ($i) {
            $settings['row_heights'] = [$i => 30];
            $settings['ai_workflows'] = [['n' => $i]];

            return $settings;
        });
        $seen = StructureVersion::current($sheetId);
        if ($seen < $last) {
            fwrite(STDERR, "version went backwards: {$last} -> {$seen}\n");
            exit(1);
        }
        $last = $seen;
        usleep(1000);
    }
    echo $last;
    exit(0);
}

if ($mode === 'insert-rows' || $mode === 'edit-rows') {
    // Two real editors (Livewire components) in two processes. One inserts rows at the top, the other
    // writes one cell per step at rows 10,000 apart. Refusals and busy answers are fine; errors are not.
    [$n, $sheetId] = [(int) $argv[2], (int) $argv[3]];
    $user = User::first();
    config(['sheet.structure_ops_enabled' => true]);
    $editor = Livewire::actingAs($user)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $sheetId]);
    $accepted = [];
    $refused = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($mode === 'insert-rows') {
            $editor->set('selectedRow', 0)->call('insertRowSelected');
        } else {
            $editor->set('editingCell', ($i * 10000).'_1')->call('saveCell', "B{$i}");
            // Only a write the editor accepted (empty notice) counts as accepted.
            if ($editor->get('structureNotice') === '') {
                $accepted[] = $i;
            } else {
                $refused++;
            }
        }
        usleep(random_int(0, 3000));
    }
    echo json_encode(['accepted' => $accepted, 'refused' => $refused, 'version' => StructureVersion::current($sheetId)]);
    exit(0);
}

if ($mode === 'scenario') {
    [$scenario, $sheetId, $tabId] = [$argv[2], (int) $argv[3], (int) $argv[4]];
    $insert = function (array $rows) {
        foreach (array_chunk($rows, 80) as $chunk) {
            DB::table('cells')->insert($chunk);
        }
    };
    $cell = fn ($r, $c, $raw, $computed, $formula) => ['spreadsheet_id' => $sheetId, 'sheet_tab_id' => $tabId, 'row_index' => $r, 'col_index' => $c, 'raw_value' => $raw, 'computed_value' => $computed, 'formula' => $formula, 'updated_at' => now()->toDateTimeString()];
    $buffer = [];
    $at = 1;
    // Seed in slices: the whole point is to measure the insert, not to run out of memory seeding.
    $add = function (array $row) use (&$buffer, $insert) {
        $buffer[] = $row;
        if (count($buffer) >= 4000) {
            $insert($buffer);
            $buffer = [];
        }
    };

    switch ($scenario) {
        case 'running-totals': // 1,000 running totals in 18,000 cells, every range cut by the insert
            for ($r = 0; $r < 1000; $r++) {
                for ($c = 0; $c < 17; $c++) {
                    $add($cell($r, $c, (string) ($r + $c), (string) ($r + $c), null));
                }
                $add($cell($r, 17, '', '0', '=SUM(A1:A'.($r + 1).')'));
            }
            break;
        case 'cells-100k-recompute': // 100,000 cells, 200 formulas that must be recomputed
            for ($r = 0; $r < 5000; $r++) {
                for ($c = 0; $c < 20; $c++) {
                    $add($cell($r, $c, (string) $r, (string) $r, null));
                }
            }
            for ($r = 0; $r < 200; $r++) {
                $add($cell($r, 20, '', '0', '=SUM(A1:A5000)'));
            }
            break;
        case 'moved-only-200k': // 200,000 cells, 50,000 of them formulas that only move
            for ($r = 0; $r < 50000; $r++) {
                $add($cell($r, 0, (string) $r, (string) $r, null));
                $add($cell($r, 1, '', '0', '=A'.($r + 1)));
                $add($cell($r, 2, 'x', 'x', null));
                $add($cell($r, 3, 'y', 'y', null));
            }
            break;
    }
    $insert($buffer);
    unset($buffer);
    Cache::flush();
    gc_collect_cycles();
    memory_reset_peak_usage();

    $started = microtime(true);
    try {
        app(SheetStructureService::class)->insertRow($sheetId, $tabId, $at);
        $result = 'ok';
    } catch (Throwable $e) {
        $result = get_class($e).': '.$e->getMessage();
    }

    echo json_encode([
        'result' => $result,
        'seconds' => round(microtime(true) - $started, 2),
        'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        'cache' => config('cache.default'),
        'memory_limit' => ini_get('memory_limit'),
        'cells' => Cell::where('sheet_tab_id', $tabId)->count(),
    ]);
    exit(0);
}
