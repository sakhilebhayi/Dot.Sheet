# Dot.Sheet: Backup Scheduling + Prune Approval Gate

## Context

Dot.Sheet's autonomy classification audit (`Dot.Brain/platforms/dot-sheet.md`, 2026-08-08) found a real, working backup command — `php artisan ops:backup` (`app/Console/Commands/RunBackupCommand.php`) — that is registered nowhere: `routes/console.php` contains only the stock `inspire` command, and `bootstrap/app.php` declares no `withSchedule()` closure at all. It only runs when an operator remembers to type it by hand. It also permanently deletes backup directories older than the retention window (`pruneOldBackups()`, same file) via `File::deleteDirectory()`, with zero confirmation step — a destructive operation that today depends entirely on whoever holds shell access remembering to run it and choosing the retention window. Both claims are confirmed against the real code, not aspirational.

The audit's own gap summary recommends exactly the split this spec builds: "turning `ops:backup` into a real `Schedule::command()` entry with alerting on failure... automatic backup = Level 1, automatic prune-to-delete = Level 2 escalation requiring approval before destructive deletion runs." No existing platform-wide operator concept exists in this repo — `app/Policies/` has only `SpreadsheetPolicy` and `TeamPolicy`, both tenant-scoped to a spreadsheet or team, neither fitting a platform-wide infrastructure decision like this one.

## Goal

`ops:backup` runs unattended, daily, and keeps creating backups exactly as it does today — no change to that part. What changes is the destructive half: instead of deleting a backup directory the moment it crosses the retention window, the command proposes the deletion (`BackupPruneProposal`, `status: pending`) and stops. A platform operator (`users.is_platform_operator`, a new flag — no existing role fits) reviews pending proposals on a new `/operator/backups` screen: approve actually deletes the directory (the only place deletion happens now); reject (reason required) leaves it on disk. Because retention is re-evaluated on every scheduled run, a rejected backup is still past retention tomorrow and gets a *fresh* proposal on the next run — the rejected row stays as permanent history, never reused.

## Changes

### 1. `users.is_platform_operator` flag

New migration, same shape as every prior platform this session that needed one (Dot.Ehail/Emall/Files/Press): boolean, default `false`, excluded from `$fillable`, added to `casts()`. Granted only by hand (direct DB/admin action) — no self-service or automated elevation path, matching precedent.

### 2. `BackupPruneProposal` model + migration

New table: `id`, `backup_path` (string, the full path to the backup directory), `backup_created_at` (timestamp, from `File::lastModified()` — the same signal `pruneOldBackups()` already uses for its retention comparison today), `status` (string: `pending`/`approved`/`rejected`, default `pending`), `reviewed_by` (nullable FK `users.id`, `nullOnDelete`), `reviewed_at` (nullable timestamp), `rejected_reason` (nullable text).

### 3. `RunBackupCommand::pruneOldBackups()` proposes instead of deleting

Same retention-window comparison as today. For each backup directory past retention: if a `BackupPruneProposal` with `status: pending` already exists for that exact `backup_path`, skip it (idempotent re-runs — a scheduled daily run shouldn't spam duplicate proposals for a directory nobody has reviewed yet). Otherwise, create a new `pending` proposal. **No call to `File::deleteDirectory()` remains in this method** — the real deletion moves to §4, the only place it happens now.

### 4. `BackupProposalQueue` Livewire component + `/operator/backups` route

New `App\Http\Middleware\EnsurePlatformOperator`, aliased `operator` in `bootstrap/app.php`'s `withMiddleware()` — same class name and convention as Dot.Press's identical middleware, for consistency across platforms that share this pattern. Gates a new route, `GET /operator/backups`.

`BackupProposalQueue` lists every `pending` proposal (path, age in days, `backup_created_at`). `approve(int $proposalId)` re-fetches the proposal, no-ops unless it's still `pending`, deletes the directory (`File::deleteDirectory()` — if the directory is already gone for any reason, that's fine, the goal state already holds), sets `status: approved`, `reviewed_by`, `reviewed_at`. `promptReject(int $proposalId)` / `cancelReject()` / `confirmReject(int $proposalId)` follow the same confirm-then-act shape used in every gate this session; `confirmReject()` requires a non-empty reason, sets `status: rejected` + `rejected_reason`, and leaves the directory untouched. A non-operator hitting the route or calling the component's methods directly gets a 403.

### 5. Scheduling + failure visibility

`bootstrap/app.php` gains a `withSchedule()` closure: `Schedule::command('ops:backup')->daily()->onFailure(fn () => Log::error('ops:backup scheduled run failed'))`. Daily, since the default 14-day retention window implies daily granularity is the point of having it. `onFailure` is Laravel's own scheduler hook — no new notification/alerting subsystem is built; a failed run becomes visible in the application log, where operational issues are already looked for, rather than silently never running and nobody noticing.

## Testing

- `RunBackupCommand`: a backup directory within retention gets no proposal; one past retention gets exactly one `pending` `BackupPruneProposal`; running the command twice without any review action doesn't create a second pending proposal for the same path; the directory itself is never deleted by the command after this change, only ever by `BackupProposalQueue::approve()`.
- `BackupProposalQueue`: an operator can approve (directory actually removed from disk, `approved`/`reviewed_by`/`reviewed_at` recorded); an operator can reject with a reason (directory left on disk, `rejected`/`rejected_reason` recorded); rejecting without a reason is a no-op; a non-operator is blocked with a 403 from both the route and the component's methods directly; acting on a proposal that's no longer `pending` (already resolved) is a no-op.
- End-to-end: after a rejection, simulating a second scheduled run (backup still past retention, no `pending` proposal exists for it since the only prior one is `rejected`) produces a fresh `pending` proposal for the same path — confirming the "defer, re-propose" behavior actually works across two runs, not just within one.

## Explicitly out of scope

- A real notification/alerting subsystem (email, Slack, etc.) for backup failures — `onFailure` logging only, per the design discussion. Wiring a real alert channel is separate, future work if the log-only signal proves insufficient.
- Restoring from a backup, or any tooling around the restore path — this spec only changes how a backup is created and how its eventual deletion is approved.
- Reconciling `BackupPruneProposal` rows whose `backup_path` no longer exists on disk for reasons outside this system (e.g., an operator manually `rm -rf`'d a directory) — `approve()` on such a row is a graceful no-op per §4, but no background job cleans up orphaned proposal rows.
- Any change to backup *content* (`backupStorageFiles()`, `backupDatabase()`, `writeManifest()`) — untouched by this spec.
- A UI for changing the retention window or backup cadence at runtime — both stay code/config-level (`--retention-days` option, `.daily()` schedule), not operator-editable settings.
