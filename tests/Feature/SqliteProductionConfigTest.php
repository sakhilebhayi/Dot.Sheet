<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Production is SQLite on a shared cPanel host: the web workers, queue:work and
 * the cron-driven scheduler all write to the same file. These guard the
 * connection options that keep them from tripping over each other
 * ("database is locked").
 */
class SqliteProductionConfigTest extends TestCase
{
    public function test_sqlite_connection_is_configured_for_concurrent_writers(): void
    {
        $sqlite = config('database.connections.sqlite');

        $this->assertTrue($sqlite['foreign_key_constraints']);
        $this->assertSame(5000, (int) $sqlite['busy_timeout']);
        $this->assertSame('wal', strtolower($sqlite['journal_mode']));
        $this->assertSame('normal', strtolower($sqlite['synchronous']));
        $this->assertSame('IMMEDIATE', strtoupper($sqlite['transaction_mode']));
    }

    public function test_a_file_backed_sqlite_database_gets_the_pragmas_applied(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dotsheet-sqlite-');

        try {
            config()->set('database.connections.pragma_check', array_merge(
                config('database.connections.sqlite'),
                ['database' => $path],
            ));

            $db = DB::connection('pragma_check');

            $this->assertSame('wal', $db->selectOne('pragma journal_mode')->journal_mode);
            $this->assertSame(5000, (int) $db->selectOne('pragma busy_timeout')->timeout);
            $this->assertSame(1, (int) $db->selectOne('pragma synchronous')->synchronous);
            $this->assertSame(1, (int) $db->selectOne('pragma foreign_keys')->foreign_keys);
        } finally {
            DB::purge('pragma_check');
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path.$suffix);
            }
        }
    }

    public function test_the_default_connection_without_an_env_override_is_sqlite(): void
    {
        // phpunit.xml forces DB_CONNECTION, so read the declared default
        // straight from the file rather than from the booted config.
        $this->assertStringContainsString(
            "env('DB_CONNECTION', 'sqlite')",
            file_get_contents(config_path('database.php')),
        );
    }
}
