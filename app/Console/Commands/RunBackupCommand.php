<?php

namespace App\Console\Commands;

use App\Models\BackupPruneProposal;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class RunBackupCommand extends Command
{
    protected $signature = 'ops:backup
        {--retention-days= : How many days of backups to retain}
        {--skip-db : Skip database backup}
        {--skip-files : Skip storage file backup}';

    protected $description = 'Create a timestamped backup scaffold for database and storage files';

    public function handle(): int
    {
        $retentionDays = (int) ($this->option('retention-days') ?: env('BACKUP_RETENTION_DAYS', 14));
        $retentionDays = max(1, $retentionDays);

        $backupRoot = storage_path('app/backups');
        $timestamp = now()->format('Ymd_His');
        $backupPath = $backupRoot.DIRECTORY_SEPARATOR.$timestamp;

        File::ensureDirectoryExists($backupPath);

        $this->info("Creating backup at: {$backupPath}");

        if (! $this->option('skip-files')) {
            $this->backupStorageFiles($backupPath);
        }

        if (! $this->option('skip-db')) {
            $this->backupDatabase($backupPath);
        }

        $this->writeManifest($backupPath);
        $this->pruneOldBackups($backupRoot, $retentionDays);

        $this->newLine();
        $this->info('Backup process complete.');

        return self::SUCCESS;
    }

    private function backupStorageFiles(string $backupPath): void
    {
        $storageAppPath = storage_path('app');
        $destinationRoot = $backupPath.DIRECTORY_SEPARATOR.'files';

        File::ensureDirectoryExists($destinationRoot);

        $candidateDirs = ['exports', 'imports', 'spreadsheets'];
        $copied = 0;

        foreach ($candidateDirs as $dir) {
            $source = $storageAppPath.DIRECTORY_SEPARATOR.$dir;
            if (! File::isDirectory($source)) {
                continue;
            }

            $destination = $destinationRoot.DIRECTORY_SEPARATOR.$dir;
            File::copyDirectory($source, $destination);
            $copied++;

            $this->line("Copied storage/app/{$dir}");
        }

        if ($copied === 0) {
            $this->warn('No known storage directories found to back up (exports/imports/spreadsheets).');
        }
    }

    private function backupDatabase(string $backupPath): void
    {
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            $this->warn('Database connection configuration not found. Skipping DB backup.');

            return;
        }

        $driver = (string) ($connection['driver'] ?? '');
        $sqlPath = $backupPath.DIRECTORY_SEPARATOR.'database.sql';

        try {
            match ($driver) {
                'mysql', 'mariadb' => $this->runMysqlDump($connection, $sqlPath),
                'pgsql' => $this->runPgsqlDump($connection, $sqlPath),
                'sqlite' => $this->copySqliteFile($connection, $backupPath),
                default => $this->warn("Unsupported database driver [{$driver}] for automatic dump. Skipping DB backup."),
            };
        } catch (Throwable $e) {
            $this->warn('Database backup failed: '.$e->getMessage());
        }
    }

    private function runMysqlDump(array $connection, string $sqlPath): void
    {
        $database = (string) ($connection['database'] ?? '');
        $username = (string) ($connection['username'] ?? '');
        $password = (string) ($connection['password'] ?? '');
        $host = (string) ($connection['host'] ?? '127.0.0.1');
        $port = (string) ($connection['port'] ?? '3306');

        if ($database === '' || $username === '') {
            $this->warn('MySQL credentials are incomplete. Skipping DB backup.');

            return;
        }

        $command = [
            'mysqldump',
            '--host='.$host,
            '--port='.$port,
            '--user='.$username,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            $database,
        ];

        $process = new Process($command, base_path(), ['MYSQL_PWD' => $password]);
        $process->setTimeout(300);
        $process->mustRun();

        File::put($sqlPath, $process->getOutput());
        $this->line('Created database.sql via mysqldump');
    }

    private function runPgsqlDump(array $connection, string $sqlPath): void
    {
        $database = (string) ($connection['database'] ?? '');
        $username = (string) ($connection['username'] ?? '');
        $password = (string) ($connection['password'] ?? '');
        $host = (string) ($connection['host'] ?? '127.0.0.1');
        $port = (string) ($connection['port'] ?? '5432');

        if ($database === '' || $username === '') {
            $this->warn('PostgreSQL credentials are incomplete. Skipping DB backup.');

            return;
        }

        $command = [
            'pg_dump',
            '--host='.$host,
            '--port='.$port,
            '--username='.$username,
            '--format=plain',
            '--no-owner',
            '--no-privileges',
            '--file='.$sqlPath,
            $database,
        ];

        $process = new Process($command, base_path(), ['PGPASSWORD' => $password]);
        $process->setTimeout(300);
        $process->mustRun();

        $this->line('Created database.sql via pg_dump');
    }

    private function copySqliteFile(array $connection, string $backupPath): void
    {
        $databasePath = (string) ($connection['database'] ?? '');

        if ($databasePath === '') {
            $this->warn('SQLite database path is missing. Skipping DB backup.');

            return;
        }

        if (! Str::startsWith($databasePath, ['/'])) {
            $databasePath = database_path($databasePath);
        }

        if (! File::exists($databasePath)) {
            $this->warn('SQLite database file not found. Skipping DB backup.');

            return;
        }

        File::copy($databasePath, $backupPath.DIRECTORY_SEPARATOR.'database.sqlite');
        $this->line('Copied SQLite database file');
    }

    private function writeManifest(string $backupPath): void
    {
        $manifest = [
            'created_at' => now()->toIso8601String(),
            'app_env' => config('app.env'),
            'app_url' => config('app.url'),
            'database_connection' => config('database.default'),
            'notes' => 'Backup scaffold generated by ops:backup. Validate restore steps before production use.',
        ];

        File::put(
            $backupPath.DIRECTORY_SEPARATOR.'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function pruneOldBackups(string $backupRoot, int $retentionDays): void
    {
        if (! File::isDirectory($backupRoot)) {
            return;
        }

        $cutoff = now()->subDays($retentionDays)->getTimestamp();

        foreach (File::directories($backupRoot) as $directory) {
            $lastModified = File::lastModified($directory);

            if ($lastModified >= $cutoff) {
                continue;
            }

            $hasPendingProposal = BackupPruneProposal::where('backup_path', $directory)
                ->where('status', 'pending')
                ->exists();

            if ($hasPendingProposal) {
                continue;
            }

            BackupPruneProposal::create([
                'backup_path' => $directory,
                'backup_created_at' => Carbon::createFromTimestamp($lastModified),
                'status' => 'pending',
            ]);

            $this->line('Proposed pruning old backup (awaiting operator approval): '.basename($directory));
        }
    }
}
