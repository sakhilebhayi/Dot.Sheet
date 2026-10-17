<?php

namespace Tests\Feature;

use App\Models\BackupPruneProposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RunBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $backupRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupRoot = storage_path('app/backups');
        File::ensureDirectoryExists($this->backupRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupRoot);
        parent::tearDown();
    }

    private function makeBackupDir(string $name, int $ageDays): string
    {
        $path = $this->backupRoot.DIRECTORY_SEPARATOR.$name;
        File::ensureDirectoryExists($path);
        touch($path, now()->subDays($ageDays)->getTimestamp());

        return $path;
    }

    private function runBackupCommand(): void
    {
        $this->artisan('ops:backup', [
            '--retention-days' => 14,
            '--skip-db' => true,
            '--skip-files' => true,
        ])->assertExitCode(0);
    }

    public function test_a_backup_within_retention_gets_no_proposal(): void
    {
        $this->makeBackupDir('20260101_000000', 3);

        $this->runBackupCommand();

        $this->assertDatabaseCount('backup_prune_proposals', 0);
    }

    public function test_a_backup_past_retention_gets_exactly_one_pending_proposal(): void
    {
        $path = $this->makeBackupDir('20260101_000000', 20);

        $this->runBackupCommand();

        $this->assertDatabaseCount('backup_prune_proposals', 1);
        $this->assertDatabaseHas('backup_prune_proposals', [
            'backup_path' => $path,
            'status' => 'pending',
        ]);
        $this->assertTrue(File::isDirectory($path), 'the command must not delete the directory itself');
    }

    public function test_running_the_command_twice_does_not_duplicate_a_pending_proposal(): void
    {
        $this->makeBackupDir('20260101_000000', 20);

        $this->runBackupCommand();
        $this->runBackupCommand();

        $this->assertDatabaseCount('backup_prune_proposals', 1);
    }

    public function test_a_rejected_proposal_gets_a_fresh_one_on_the_next_eligible_run(): void
    {
        $path = $this->makeBackupDir('20260101_000000', 20);

        $this->runBackupCommand();

        $existing = BackupPruneProposal::where('backup_path', $path)->firstOrFail();
        $existing->update(['status' => 'rejected', 'rejected_reason' => 'Not yet.']);

        $this->runBackupCommand();

        $this->assertDatabaseCount('backup_prune_proposals', 2);
        $this->assertSame(1, BackupPruneProposal::where('backup_path', $path)->where('status', 'pending')->count());
    }

    public function test_the_sqlite_copy_is_a_consistent_online_backup(): void
    {
        $dir = sys_get_temp_dir().'/sheet-backup-'.uniqid();
        mkdir($dir);
        $db = $dir.'/live.sqlite';

        $live = new \SQLite3($db);
        $live->exec('create table t (id integer primary key, name text)');
        $live->exec("insert into t (name) values ('kept')");
        $live->close();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $db);

        $this->artisan('ops:backup', ['--retention-days' => 14, '--skip-files' => true])
            ->expectsOutputToContain('Copied SQLite database file')
            ->assertExitCode(0);

        $copies = glob($this->backupRoot.'/*/database.sqlite') ?: [];
        $this->assertCount(1, $copies);

        $copy = new \SQLite3($copies[0], SQLITE3_OPEN_READONLY);
        $this->assertSame('kept', $copy->querySingle('select name from t'));
        $this->assertSame('ok', $copy->querySingle('pragma integrity_check'));
        $copy->close();
    }
}
