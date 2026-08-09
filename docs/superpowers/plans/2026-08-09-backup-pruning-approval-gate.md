# Backup Scheduling + Prune Approval Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `ops:backup` runs unattended daily. Backup creation is unchanged; destructive pruning no longer deletes immediately — it proposes (`BackupPruneProposal`, `status: pending`) and a platform operator approves (real deletion happens here) or rejects (reason required, directory stays; re-proposed on the next eligible run since retention is re-evaluated every time).

**Architecture:** New `users.is_platform_operator` flag (no existing role fits a platform-wide infra decision). New `BackupPruneProposal` model/table records each deletion candidate. `RunBackupCommand::pruneOldBackups()` creates proposals instead of deleting. New `EnsurePlatformOperator` middleware (same class/convention as Dot.Press) + `BackupProposalQueue` Livewire component + `/operator/backups` route provide the review surface. `bootstrap/app.php` gains scheduling for the command and a middleware alias.

**Tech Stack:** Laravel 13 (`withSchedule()`/`withMiddleware()` in `bootstrap/app.php`), Livewire 3 (`livewire/livewire` ^3.6.4), PHPUnit.

## Global Constraints

- No change to backup *content* creation (`backupStorageFiles()`, `backupDatabase()`, `writeManifest()`) — untouched.
- `pruneOldBackups()` never calls `File::deleteDirectory()` again after this plan — the only place that call remains is `BackupProposalQueue::approve()`.
- A `pending` proposal is never duplicated for the same `backup_path` on a re-run; a `rejected` proposal does not block a fresh proposal being created for that same path on a later run once it's still past retention (per the spec's "defer, re-propose" decision) — each run's outcome is its own row, `rejected` rows are never reused or overwritten.
- Reviewer authority is `users.is_platform_operator` (new boolean flag, default `false`, excluded from `$fillable`, granted only by hand) — not a new Policy on a model, since this is a flat platform-wide capability, not scoped to a specific resource's ownership.
- `EnsurePlatformOperator` middleware must match `~/Dot/Dot.Press/app/Http/Middleware/EnsurePlatformOperator.php` exactly (same class name, same `abort_unless($request->user()?->is_platform_operator, 403)` body) for consistency across the ecosystem's `is_platform_operator` platforms.
- This repo's own `CLAUDE.md` states: "Do not create verification scripts or tinker when tests cover that functionality and prove they work." Task 3 relies on the automated test suite only — no manual tinker verification step, matching this explicit project rule.
- Every `git add` lists files explicitly, never `-A`/`.` — this repo had pre-existing unrelated uncommitted changes (`application-mark.blade.php`, `navigation-menu.blade.php`, `welcome.blade.php`, mark images) stashed before this work started.

---

### Task 1: `is_platform_operator` flag + `BackupPruneProposal` + command rewrite

**Files:**
- Create: `database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php`
- Modify: `app/Models/User.php`
- Create: `database/migrations/2026_08_09_000002_create_backup_prune_proposals_table.php`
- Create: `app/Models/BackupPruneProposal.php`
- Modify: `app/Console/Commands/RunBackupCommand.php`
- Test: `tests/Feature/RunBackupCommandTest.php`

**Interfaces:**
- Produces: `users.is_platform_operator` (bool), `BackupPruneProposal::create(array $attributes)` with fillable `backup_path`, `backup_created_at`, `status`, `reviewed_by`, `reviewed_at`, `rejected_reason`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/RunBackupCommandTest.php`:

```php
<?php

namespace Tests\Feature;

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

        $existing = \App\Models\BackupPruneProposal::where('backup_path', $path)->firstOrFail();
        $existing->update(['status' => 'rejected', 'rejected_reason' => 'Not yet.']);

        $this->runBackupCommand();

        $this->assertDatabaseCount('backup_prune_proposals', 2);
        $this->assertSame(1, \App\Models\BackupPruneProposal::where('backup_path', $path)->where('status', 'pending')->count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/RunBackupCommandTest.php`
Expected: FAIL — `backup_prune_proposals` table doesn't exist yet, and `pruneOldBackups()` still deletes directories immediately instead of proposing.

- [ ] **Step 3: Write the `is_platform_operator` migration**

Create `database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_operator')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_operator');
        });
    }
};
```

- [ ] **Step 4: Add the cast to `User`**

In `app/Models/User.php`, update `casts()`:

```php
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_operator' => 'boolean',
        ];
    }
```

Do **not** add `is_platform_operator` to `$fillable` — granted only by hand (direct DB/admin action), matching every prior platform this session that used this flag.

- [ ] **Step 5: Write the `backup_prune_proposals` migration**

Create `database/migrations/2026_08_09_000002_create_backup_prune_proposals_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_prune_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('backup_path');
            $table->timestamp('backup_created_at');
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'backup_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_prune_proposals');
    }
};
```

- [ ] **Step 6: Create the `BackupPruneProposal` model**

Create `app/Models/BackupPruneProposal.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupPruneProposal extends Model
{
    protected $fillable = [
        'backup_path', 'backup_created_at', 'status', 'reviewed_by', 'reviewed_at', 'rejected_reason',
    ];

    protected $casts = [
        'backup_created_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
```

- [ ] **Step 7: Run migrations**

Run: `php artisan migrate`
Expected: both new migrations run with no errors.

- [ ] **Step 8: Rewrite `RunBackupCommand::pruneOldBackups()`**

In `app/Console/Commands/RunBackupCommand.php`, add an import:

```php
use App\Models\BackupPruneProposal;
```

(add alongside the existing `use Illuminate\Support\Facades\File;` import)

Replace the existing `private function pruneOldBackups(...)` method with:

```php
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
                'backup_created_at' => \Illuminate\Support\Carbon::createFromTimestamp($lastModified),
                'status' => 'pending',
            ]);

            $this->line('Proposed pruning old backup (awaiting operator approval): '.basename($directory));
        }
    }
```

This method no longer calls `File::deleteDirectory()` at all -- it only ever creates a `BackupPruneProposal` row. Real deletion moves entirely to Task 2's `BackupProposalQueue::approve()`.

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test tests/Feature/RunBackupCommandTest.php`
Expected: PASS (4 tests)

- [ ] **Step 10: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: passes; re-run Step 9 if it reformats anything.

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php \
  database/migrations/2026_08_09_000002_create_backup_prune_proposals_table.php \
  app/Models/User.php app/Models/BackupPruneProposal.php \
  app/Console/Commands/RunBackupCommand.php tests/Feature/RunBackupCommandTest.php \
  docs/superpowers/plans/2026-08-09-backup-pruning-approval-gate.md
git commit -m "$(cat <<'EOF'
feat: backup pruning proposes instead of deleting immediately

pruneOldBackups() no longer calls File::deleteDirectory() -- it
creates a BackupPruneProposal (status: pending) for each backup
directory past the retention window instead, unless one is already
pending for that exact path (idempotent re-runs). Real deletion
happens nowhere in this command anymore.

New is_platform_operator flag on users -- no existing role in this
repo fits a platform-wide infrastructure decision like reviewing a
backup deletion; app/Policies/ only has SpreadsheetPolicy and
TeamPolicy, both tenant-scoped.

Rejecting a proposal is not remembered as a permanent "keep forever"
-- since retention is re-evaluated on every scheduled run, a rejected
backup that's still past retention gets a fresh proposal next time,
confirmed by test_a_rejected_proposal_gets_a_fresh_one_on_the_next_eligible_run.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Scheduling + `EnsurePlatformOperator` + `BackupProposalQueue` review UI

**Files:**
- Modify: `bootstrap/app.php`
- Create: `app/Http/Middleware/EnsurePlatformOperator.php`
- Create: `app/Livewire/BackupProposalQueue.php`
- Create: `resources/views/livewire/backup-proposal-queue.blade.php`
- Create: `resources/views/operator/backups.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BackupProposalQueueTest.php`

**Interfaces:**
- Consumes: `BackupPruneProposal` (Task 1), `users.is_platform_operator` (Task 1).
- Produces: `BackupProposalQueue::approve(int $proposalId)`, `promptReject(int $proposalId)`, `cancelReject()`, `confirmReject(int $proposalId)`, `$rejectingProposalId`/`$rejectReason` component state, `$this->pendingProposals` computed property.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/BackupProposalQueueTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\BackupProposalQueue;
use App\Models\BackupPruneProposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class BackupProposalQueueTest extends TestCase
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

    private function pendingProposal(): BackupPruneProposal
    {
        $path = $this->backupRoot.DIRECTORY_SEPARATOR.'20260101_000000';
        File::ensureDirectoryExists($path);

        return BackupPruneProposal::create([
            'backup_path' => $path,
            'backup_created_at' => now()->subDays(20),
            'status' => 'pending',
        ]);
    }

    private function operator(): User
    {
        return User::factory()->create(['is_platform_operator' => true]);
    }

    public function test_operator_can_approve_and_the_directory_is_actually_deleted(): void
    {
        $proposal = $this->pendingProposal();
        $operator = $this->operator();

        Livewire::actingAs($operator)
            ->test(BackupProposalQueue::class)
            ->call('approve', $proposal->id);

        $fresh = $proposal->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($operator->id, $fresh->reviewed_by);
        $this->assertFalse(File::isDirectory($proposal->backup_path));
    }

    public function test_operator_can_reject_with_a_reason_and_the_directory_is_untouched(): void
    {
        $proposal = $this->pendingProposal();
        $operator = $this->operator();

        Livewire::actingAs($operator)
            ->test(BackupProposalQueue::class)
            ->call('promptReject', $proposal->id)
            ->set('rejectReason', 'Not ready, still needed for an audit.')
            ->call('confirmReject', $proposal->id);

        $fresh = $proposal->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Not ready, still needed for an audit.', $fresh->rejected_reason);
        $this->assertTrue(File::isDirectory($proposal->backup_path));
    }

    public function test_rejecting_without_a_reason_is_a_noop(): void
    {
        $proposal = $this->pendingProposal();
        $operator = $this->operator();

        Livewire::actingAs($operator)
            ->test(BackupProposalQueue::class)
            ->call('promptReject', $proposal->id)
            ->set('rejectReason', '')
            ->call('confirmReject', $proposal->id);

        $this->assertSame('pending', $proposal->fresh()->status);
    }

    public function test_acting_on_a_proposal_no_longer_pending_is_a_noop(): void
    {
        $proposal = $this->pendingProposal();
        $operator = $this->operator();
        $proposal->update(['status' => 'approved']); // already resolved by someone else

        Livewire::actingAs($operator)
            ->test(BackupProposalQueue::class)
            ->call('approve', $proposal->id);

        $this->assertTrue(File::isDirectory($proposal->backup_path), 'a no-op approve must not delete anything');
    }

    public function test_non_operator_is_blocked(): void
    {
        $this->pendingProposal();
        $regularUser = User::factory()->create(['is_platform_operator' => false]);

        $this->withoutExceptionHandling();
        $this->expectException(AuthorizationException::class);

        Livewire::actingAs($regularUser)->test(BackupProposalQueue::class);
    }

    public function test_operator_backups_route_requires_authentication(): void
    {
        $this->get('/operator/backups')->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/BackupProposalQueueTest.php`
Expected: FAIL — `App\Livewire\BackupProposalQueue` doesn't exist yet, `/operator/backups` route doesn't exist yet.

- [ ] **Step 3: Create `EnsurePlatformOperator` middleware**

Create `app/Http/Middleware/EnsurePlatformOperator.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_platform_operator, 403);

        return $next($request);
    }
}
```

Identical to `~/Dot/Dot.Press/app/Http/Middleware/EnsurePlatformOperator.php` -- same class name and body, for consistency across the ecosystem's `is_platform_operator` platforms.

- [ ] **Step 4: Register the middleware alias and the schedule**

In `bootstrap/app.php`, replace the full file with:

```php
<?php

use App\Http\Middleware\EnsurePlatformOperator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'operator' => EnsurePlatformOperator::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('ops:backup')
            ->daily()
            ->onFailure(function (): void {
                Log::error('ops:backup scheduled run failed');
            });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

- [ ] **Step 5: Create the `BackupProposalQueue` Livewire component**

Create `app/Livewire/BackupProposalQueue.php`:

```php
<?php

namespace App\Livewire;

use App\Models\BackupPruneProposal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Computed;
use Livewire\Component;

class BackupProposalQueue extends Component
{
    public ?int $rejectingProposalId = null;

    public string $rejectReason = '';

    public function mount(): void
    {
        $this->ensureOperator();
    }

    #[Computed]
    public function pendingProposals(): Collection
    {
        return BackupPruneProposal::where('status', 'pending')
            ->latest('backup_created_at')
            ->get();
    }

    public function approve(int $proposalId): void
    {
        $this->ensureOperator();

        $proposal = BackupPruneProposal::find($proposalId);

        if (! $proposal || $proposal->status !== 'pending') {
            return;
        }

        if (File::isDirectory($proposal->backup_path)) {
            File::deleteDirectory($proposal->backup_path);
        }

        $proposal->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        unset($this->pendingProposals);
    }

    public function promptReject(int $proposalId): void
    {
        $this->ensureOperator();

        $this->rejectingProposalId = $proposalId;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingProposalId = null;
        $this->rejectReason = '';
    }

    public function confirmReject(int $proposalId): void
    {
        $this->ensureOperator();

        if (trim($this->rejectReason) === '') {
            return;
        }

        $proposal = BackupPruneProposal::find($proposalId);

        if (! $proposal || $proposal->status !== 'pending') {
            return;
        }

        $proposal->update([
            'status' => 'rejected',
            'rejected_reason' => $this->rejectReason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->rejectingProposalId = null;
        $this->rejectReason = '';
        unset($this->pendingProposals);
    }

    private function ensureOperator(): void
    {
        throw_unless(auth()->user()?->is_platform_operator, AuthorizationException::class);
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.backup-proposal-queue');
    }
}
```

Note on `ensureOperator()`: this throws `Illuminate\Auth\Access\AuthorizationException` via `throw_unless()`, not the middleware's `abort_unless(..., 403)` -- both render as a 403 to the browser (Laravel's exception handler maps `AuthorizationException` to a 403 response the same way), but `AuthorizationException` matches this session's established Livewire-gate testing convention (`expectException(AuthorizationException::class)`, used identically in Dot.Projects and Dot.Pulse this session) and is easier to assert on directly in a test than catching a generic `HttpException` and checking its status code. The route-level `operator` middleware (Step 4) is a second, independent layer -- it protects the initial page load; `ensureOperator()` inside each component method protects every subsequent Livewire action call too, since Livewire's own update requests do not re-run route middleware or `mount()`.

- [ ] **Step 6: Create the component view**

Create `resources/views/livewire/backup-proposal-queue.blade.php`:

```blade
<div class="max-w-3xl">
    @if($this->pendingProposals->isEmpty())
        <div class="bg-white rounded-xl shadow p-5 text-sm text-gray-400">
            No backups awaiting pruning review.
        </div>
    @endif

    @foreach($this->pendingProposals as $proposal)
        <div class="bg-white rounded-xl shadow p-5 mb-4">
            <div class="flex items-center justify-between mb-2">
                <div>
                    <p class="text-sm font-medium text-gray-800">{{ basename($proposal->backup_path) }}</p>
                    <p class="text-xs text-gray-400">
                        Created {{ $proposal->backup_created_at->format('M d, Y H:i') }}
                        &middot; {{ $proposal->backup_created_at->diffInDays(now()) }} days old
                    </p>
                </div>
                <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700">
                    Past retention
                </span>
            </div>

            <p class="text-xs text-gray-400 mb-3 break-all">{{ $proposal->backup_path }}</p>

            @if($rejectingProposalId === $proposal->id)
                <div class="flex items-center gap-2">
                    <input type="text" wire:model="rejectReason" placeholder="Reason for keeping this backup"
                        class="flex-1 border border-gray-300 rounded px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                    <button wire:click="confirmReject({{ $proposal->id }})" class="text-xs px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white font-medium">
                        Confirm Reject
                    </button>
                    <button wire:click="cancelReject" class="text-xs px-3 py-1.5 rounded bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium">
                        Cancel
                    </button>
                </div>
            @else
                <div class="flex items-center gap-2">
                    <button wire:click="approve({{ $proposal->id }})" class="text-xs px-3 py-1.5 rounded bg-green-600 hover:bg-green-700 text-white font-medium">
                        Approve Deletion
                    </button>
                    <button wire:click="promptReject({{ $proposal->id }})" class="text-xs px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white font-medium">
                        Reject
                    </button>
                </div>
            @endif
        </div>
    @endforeach
</div>
```

- [ ] **Step 7: Create the page view**

Create `resources/views/operator/backups.blade.php`:

```blade
<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;max-width:1100px;">

    <div style="margin-bottom:2rem;">
        <h1 style="font-family:'Manrope',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">Backup Pruning Queue</h1>
        <p style="font-size:0.78rem;color:#71717a;margin:0;">Backups past the retention window, awaiting your approval to delete</p>
    </div>

    <livewire:backup-proposal-queue />

</div>

</x-app-layout>
```

- [ ] **Step 8: Add the route**

In `routes/web.php`, add inside the existing `auth:sanctum` / `jetstream.auth_session` / `verified` group (matching Dot.Press's exact nested-group structure):

```php
    Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
        Route::get('/backups', fn () => view('operator.backups'))->name('backups.index');
    });
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test tests/Feature/BackupProposalQueueTest.php`
Expected: PASS (6 tests)

- [ ] **Step 10: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: passes; re-run Step 9 if it reformats anything.

- [ ] **Step 11: Commit**

```bash
git add bootstrap/app.php app/Http/Middleware/EnsurePlatformOperator.php \
  app/Livewire/BackupProposalQueue.php resources/views/livewire/backup-proposal-queue.blade.php \
  resources/views/operator/backups.blade.php routes/web.php tests/Feature/BackupProposalQueueTest.php
git commit -m "$(cat <<'EOF'
feat: schedule ops:backup + operator review queue for pruning

bootstrap/app.php now schedules ops:backup daily (Level 1, unattended
-- previously registered nowhere, confirmed via routes/console.php
having only the stock inspire command) with onFailure() logging, per
the audit's own "alerting on failure" recommendation -- no new
notification subsystem, just Laravel's built-in scheduler hook.

New /operator/backups route + BackupProposalQueue Livewire component:
lists every pending BackupPruneProposal and lets an operator approve
(the only place a backup directory is actually deleted now) or reject
with a reason (directory kept; re-proposed on a later run if still
past retention).

EnsurePlatformOperator middleware protects the initial page load;
each Livewire action method independently re-checks
is_platform_operator too, since Livewire's own update requests bypass
route middleware and don't re-run mount() -- matches this session's
established defense-in-depth convention for Livewire gates.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Full regression

**Files:** none new — verification only.

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: 0 failures — confirms Tasks 1-2 didn't break `SpreadsheetCoreOperationsTest`, the Jetstream scaffolding tests, `FormulaEvaluatorServiceTest`, or anything else.

- [ ] **Step 2: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: passes; re-run Step 1 if it reformats anything.

- [ ] **Step 3: Report completion**

No manual tinker verification for this task -- this repo's own `CLAUDE.md` states "Do not create verification scripts or tinker when tests cover that functionality and prove they work," and Tasks 1-2's tests already exercise the real end-to-end lifecycle: a real filesystem directory created, proposed via the real `ops:backup` command, approved via the real `BackupProposalQueue` Livewire component, and actually deleted from disk (`RunBackupCommandTest`, `BackupProposalQueueTest`). No commit for this task — it's verification only. If Step 1 finds any failures, stop and fix them (return to the relevant earlier task) before considering this plan complete.

## Self-Review Notes

- **Spec coverage:** Task 1 covers spec §1-§3 (`is_platform_operator` flag, `BackupPruneProposal`, `pruneOldBackups()` rewrite). Task 2 covers spec §4-§5 (review UI + scheduling/failure visibility). Task 3 covers the spec's implicit "this all actually works together" requirement, adapted to this repo's own no-tinker rule.
- **Placeholder scan:** none — every step has literal file content, including full migration/model/middleware/Livewire/view contents and the complete replacement `bootstrap/app.php`.
- **Type consistency:** `BackupPruneProposal::create()`'s fillable keys, `BackupProposalQueue`'s `approve`/`promptReject`/`cancelReject`/`confirmReject` signatures and `$rejectingProposalId`/`$rejectReason` properties, and `RunBackupCommand::pruneOldBackups()`'s no-longer-deleting behavior are used identically everywhere they're referenced across both tasks.
- **AuthorizationException vs abort_unless, addressed explicitly:** Task 2 Step 5's note explains why the Livewire component uses `throw_unless(..., AuthorizationException::class)` while the middleware (same task, Step 3) keeps `abort_unless(..., 403)` -- a deliberate, justified choice, not an unexplained inconsistency between two files in the same task.
- **"Reject = defer, re-propose" behavior tested across two runs, not assumed:** Task 1's `test_a_rejected_proposal_gets_a_fresh_one_on_the_next_eligible_run` explicitly simulates two command runs with a rejection in between, since this is the one gate this session where a reject decision faces a recurring scheduled reassessment rather than a one-shot request.
- **No manual tinker step, per this repo's own rule:** Task 3 explains why it skips the manual-verification step every other platform's Task 3 included this session, rather than silently including or silently omitting it without explanation.
