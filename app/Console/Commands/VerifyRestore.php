<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The restore drill's last step (the shape of Dot.HR's): proves a copy restored from a backup is usable
 * by THIS code and THIS APP_KEY.
 *
 *   php artisan sheet:verify-restore --connection=restore                  # the restored copy
 *   php artisan sheet:verify-restore --connection=restore --against=pgsql  # and compare it with the live tables
 *
 * Checks every migration this code ships has run on the copy, prints row counts (totals only) for the tables that
 * hold the businesses' work, compares every table's count with --against when given (a backup taken moments
 * earlier may trail the live copy, so a lower count is reported, a higher one fails), and decrypts a sample of the
 * encrypted outbox payloads with this APP_KEY. It reads only; it never writes to either database.
 */
class VerifyRestore extends Command
{
    protected $signature = 'sheet:verify-restore {--connection=restore : Database connection holding the restored copy} {--against= : A connection to compare row counts with} {--sample=25 : Encrypted values to test}';

    protected $description = 'Check a restored Dot.Sheet database: schema, row counts, and that encrypted values decrypt with this APP_KEY';

    /** The tables that hold the businesses' work, counted in the report. */
    public const COUNTED = ['teams', 'users', 'spreadsheets', 'sheet_tabs', 'cells', 'spreadsheet_versions', 'comments', 'chart_configs', 'pivot_tables',
        'named_ranges', 'sheet_protections', 'workbook_approvals', 'workbook_filings', 'audit_events', 'integration_deliveries'];

    public function handle(Migrator $migrator): int
    {
        $name = (string) $this->option('connection');
        if ($name === (string) config('database.default') && $this->option('against') === null) {
            $this->warn("Checking the app's own database \"{$name}\"; pass --connection=restore to check a restored copy.");
        }

        try {
            $db = DB::connection($name);
            $db->getPdo();
        } catch (Throwable) {
            $this->error("Cannot connect to the \"{$name}\" database.");

            return self::FAILURE;
        }

        $failed = false;
        $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
        $pending = array_values(array_diff($files, $db->table('migrations')->pluck('migration')->all()));
        if ($pending !== []) {
            $failed = true;
            $this->error(count($pending).' migration(s) not applied on the copy: '.implode(', ', $pending));
        } else {
            $this->line('Schema: all '.count($files).' migrations applied.');
        }

        $tables = $this->tables($db);
        $against = null;
        if ($this->option('against')) {
            try {
                $against = DB::connection((string) $this->option('against'));
                $against->getPdo();
            } catch (Throwable) {
                $this->error('Cannot connect to the "'.$this->option('against').'" database.');

                return self::FAILURE;
            }
        }

        $rows = [];
        $trailing = 0;
        foreach ($against ? $this->tables($against) : self::COUNTED as $table) {
            $copy = in_array($table, $tables, true) ? $db->table($table)->count() : null;
            $live = $against?->table($table)->count();
            if ($copy === null || ($live !== null && $copy > $live)) {
                $failed = true;
            }
            $trailing += $live !== null && $copy !== null && $copy < $live ? 1 : 0;
            if (in_array($table, self::COUNTED, true) || $copy === null || $copy !== $live) {
                $rows[] = [$table, $copy ?? 'missing', ...($against ? [$live] : [])];
            }
        }
        $this->table(['Table', 'Rows in the copy', ...($against ? ['Rows now'] : [])], $rows);
        if ($against) {
            $this->line(count($this->tables($against)).' tables compared; '.($trailing === 0 ? 'every count matches.' : "{$trailing} trail the live copy (written after the backup)."));
        }

        $tested = 0;
        $broken = 0;
        if (in_array('integration_deliveries', $tables, true)) {
            foreach ($db->table('integration_deliveries')->orderByDesc('id')->limit((int) $this->option('sample'))->pluck('payload') as $value) {
                $tested++;
                try {
                    Crypt::decryptString((string) $value);
                } catch (Throwable) {
                    $broken++;
                }
            }
        }
        if ($broken > 0) {
            $failed = true;
            $this->error("{$broken} of {$tested} sampled encrypted values do NOT decrypt with this APP_KEY: the copy and the key do not belong together. Find the APP_KEY that was live when the backup was taken.");
        } else {
            $this->line($tested === 0 ? 'Encryption: no encrypted values to test.' : "Encryption: {$tested} sampled values decrypt with this APP_KEY.");
        }

        $failed ? $this->error('Restore check FAILED.') : $this->info('Restore check passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> */
    private function tables(Connection $db): array
    {
        $schema = $db->getSchemaBuilder();

        return collect($schema->getTables())->pluck('name')->reject(fn ($t) => in_array($t, ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'], true))->sort()->values()->all();
    }
}
