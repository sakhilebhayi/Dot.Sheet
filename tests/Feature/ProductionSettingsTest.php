<?php

namespace Tests\Feature;

use App\Models\Spreadsheet;
use App\Services\SheetStructureService;
use App\Services\StructureChangeRefused;
use App\Services\StructureVersion;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * What phpunit's defaults (in-memory SQLite, array cache, 512M) cannot show: the production
 * settings on a real file, in real separate processes. SQLite in WAL mode, BEGIN IMMEDIATE,
 * busy_timeout 5000, CACHE_STORE=database, memory_limit 128M, one scratch file per test.
 */
class ProductionSettingsTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir().'/dotsheet-prod-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($this->file);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->file.$suffix);
        }

        parent::tearDown();
    }

    /**
     * @param  list<string>  $arguments
     */
    private function child(array $arguments, string $memoryLimit = '128M'): Process
    {
        return new Process([PHP_BINARY, '-d', "memory_limit={$memoryLimit}", base_path('tests/Support/production_child.php'), ...$arguments], base_path(), [
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->file, 'DB_JOURNAL_MODE' => 'wal', 'DB_TRANSACTION_MODE' => 'IMMEDIATE',
            'DB_BUSY_TIMEOUT' => '5000', 'SHEET_STRUCTURE_OPS_ENABLED' => 'true', 'CACHE_STORE' => 'database', 'APP_ENV' => 'production', 'DB_URL' => '',
        ], null, 120);
    }

    /**
     * @return array{0: int, 1: int} spreadsheet id, tab id
     */
    private function setUpSheet(): array
    {
        $setup = $this->child(['setup'], '512M');
        $setup->mustRun();

        return array_map('intval', explode(' ', trim($setup->getOutput())));
    }

    /**
     * @return array{result: string, seconds: float, peak_mb: float, cache: string, memory_limit: string, cells: int}
     */
    private function scenario(string $name): array
    {
        [$sheetId, $tabId] = $this->setUpSheet();
        $run = $this->child(['scenario', $name, (string) $sheetId, (string) $tabId]);
        $run->mustRun();

        return json_decode($run->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_a_thousand_running_totals_finish_quickly_in_128_megabytes_with_the_database_cache(): void
    {
        $r = $this->scenario('running-totals');

        $this->assertSame('ok', $r['result']);
        $this->assertSame('database', $r['cache']);
        $this->assertSame('128M', $r['memory_limit']);
        $this->assertLessThan(20, $r['seconds']);
        $this->assertLessThan(128, $r['peak_mb']);
        $this->assertSame(18000, $r['cells']);
    }

    public function test_a_hundred_thousand_cells_with_formulas_to_recompute_fit_in_128_megabytes(): void
    {
        $r = $this->scenario('cells-100k-recompute');

        $this->assertSame('ok', $r['result']);
        $this->assertLessThan(20, $r['seconds']);
        $this->assertLessThan(128, $r['peak_mb']);
    }

    public function test_fifty_thousand_formulas_that_only_move_fit_in_128_megabytes(): void
    {
        $r = $this->scenario('moved-only-200k');

        $this->assertSame('ok', $r['result']);
        $this->assertLessThan(20, $r['seconds']);
        $this->assertLessThan(128, $r['peak_mb']);
    }

    public function test_the_structure_version_never_goes_backwards_while_settings_are_written_in_another_process(): void
    {
        [$sheetId, $tabId] = $this->setUpSheet();

        $inserts = $this->child(['bump', '60', (string) $sheetId, (string) $tabId], '512M');
        $settings = $this->child(['settings', '300', (string) $sheetId], '512M');
        $inserts->start();
        $settings->start();
        $inserts->wait();
        $settings->wait();

        $this->assertSame(0, $settings->getExitCode(), $settings->getErrorOutput().$settings->getOutput());
        $this->assertSame(0, $inserts->getExitCode(), $inserts->getErrorOutput());
        $this->assertSame('60', trim($inserts->getOutput()));

        Config::set('database.connections.child', array_merge(config('database.connections.sqlite'), ['database' => $this->file]));
        $this->assertSame(60, (int) DB::connection('child')->table('spreadsheets')->where('id', $sheetId)->value('structure_version'));
    }

    public function test_an_insert_that_cannot_get_the_write_lock_in_time_says_the_sheet_is_busy(): void
    {
        [$sheetId, $tabId] = $this->setUpSheet();

        config(['sheet.structure_ops_enabled' => true]);
        Config::set('database.connections.locked', array_merge(config('database.connections.sqlite'), ['database' => $this->file, 'busy_timeout' => 200]));
        Config::set('database.default', 'locked');
        DB::purge('locked');

        // Another connection holds the write lock (a long transaction, a queue worker ...).
        $holder = new \PDO('sqlite:'.$this->file);
        $holder->exec('PRAGMA journal_mode=WAL');
        $holder->exec('BEGIN IMMEDIATE');

        try {
            app(SheetStructureService::class)->insertRow($sheetId, $tabId, 0);
            $this->fail('expected the insert to give up');
        } catch (StructureChangeRefused $e) {
            $this->assertSame(SheetStructureService::BUSY_MESSAGE, $e->getMessage());
        } finally {
            $holder->exec('ROLLBACK');
        }

        // Once the lock is free the same insert goes through, and nothing was half done.
        app(SheetStructureService::class)->insertRow($sheetId, $tabId, 0);
        $this->assertSame(1, StructureVersion::current($sheetId));
    }

    public function test_two_editors_race_an_inserter_and_a_writer_in_separate_processes_with_the_switch_on(): void
    {
        [$sheetId, $tabId] = $this->setUpSheet();

        $inserter = $this->child(['insert-rows', '40', (string) $sheetId], '512M');
        $writer = $this->child(['edit-rows', '60', (string) $sheetId], '512M');
        $settings = $this->child(['settings', '200', (string) $sheetId], '512M');
        foreach ([$inserter, $writer, $settings] as $process) {
            $process->start();
        }
        foreach ([$inserter, $writer, $settings] as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        }

        $written = json_decode($writer->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $inserts = json_decode($inserter->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        Config::set('database.connections.child', array_merge(config('database.connections.sqlite'), ['database' => $this->file]));
        $stored = DB::connection('child')->table('cells')->where('sheet_tab_id', $tabId)->pluck('raw_value')->all();
        sort($stored);
        $expected = array_map(fn ($i) => "B{$i}", $written['accepted']);
        sort($expected);

        // Every write the writer was told succeeded is stored exactly once and none it was told was refused is:
        // no stale write landed, none was lost, none overwrote another.
        $this->assertSame($expected, $stored);
        $this->assertSame(60, count($written['accepted']) + $written['refused']);
        $version = (int) DB::connection('child')->table('spreadsheets')->where('id', $sheetId)->value('structure_version');
        $this->assertSame(40, $version, 'every one of the 40 inserts committed and the counter never went backwards');
        $this->assertGreaterThan(0, count($written['accepted']));
    }
}
