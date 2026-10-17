# Deploying Dot.Sheet

Production is `https://sheets.infodot.co.za`, on the shared cPanel host that also runs the other Dot apps. Everything goes through GitHub Actions; nothing is uploaded by hand any more.

| Workflow | Runs | What it does |
|---|---|---|
| `ci.yml` | every PR and push to `main`; called by `deploy.yml` | Pint, the PHP test suite, the frontend build and a check that the Vite manifest contains every entry the views use. One required check: **Quality gate**. Composer and npm audits report as warnings only. |
| `drift.yml` | manual, **read-only** | Compares every tracked file of `main` with the host's copy (sha256) and prints counts and paths. Run it before the takeover. |
| `deploy.yml` | manual, `confirm=adopt` once, then `confirm=deploy` | See below. |
| `server-logs.yml` | manual, read-only | Tails the newest Laravel log. |

## Why there may be a takeover step

Nothing in the repository says how the app folder on the host was put there, or whether it is a git checkout of `main`. Dot.Sheet may have been uploaded by hand (as Dot.Projects was, which also left it without its built `public/build` and made its login pages answer 500). **Run the drift report first**: it says whether the host is a checkout and how many files differ from `main`. The host has no Node, so the frontend is built on the runner and synced over either way.

## First time (once)

1. Set the repository secrets: `DEPLOY_HOST` (`sheets.infodot.co.za`), `DEPLOY_USER` (`infodotc`), `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`. `DEPLOY_PATH` is optional: left unset, the app folder is the one `artisan` found under the docroot.
2. Run **Drift Report**. It says how many files differ and lists them. Read it.
3. Run **Deploy to Production** with `confirm=adopt` once. It works on an uploaded copy (no `.git`) and on a checkout of the host's own history that was never pushed; for the latter it keeps that history on a local `host-before-adopt-*` branch. If the folder is already a checkout of this repo's `main`, use `confirm=deploy` instead. It makes a rollback copy, turns the folder into a checkout of `main` without deleting anything untracked (`.env`, `storage/`, `database/`), installs dependencies, ships the build, forces `APP_DEBUG=false`, lists pending migrations (it does **not** run them unless you also pass `migrate=migrate`), rebuilds the caches and checks the site.
4. Check the pending-migrations list, then run `confirm=deploy` to apply them.

## Every release after that

Run **Deploy to Production** with `confirm=deploy` (`gh workflow run deploy.yml -f confirm=deploy`). It runs the CI gate first.

## Safety nets

- A rollback copy of the app folder (without `vendor/`, `node_modules/`, logs and the build) is written to `~/dot-sheet-<mode>-<time>.tgz`; the newest three are kept. To restore: stop writes, `tar xzf` it over the app folder, `php artisan up`.
- A failure after maintenance mode starts **leaves the site down** and says so, with the copy's name. A failure before it says the site is untouched.
- `APP_DEBUG` is forced to `false` on every run, because with it on any error prints a ~840 KB debug page to every visitor. `APP_ENV=local` and an `http://` `APP_URL` are reported as warnings, not changed.
- Cron: `ops:backup` is scheduled daily in `bootstrap/app.php` (so `schedule:run` must run), plus 3 queued classes on the default queue (a worker line). The deploy installs 2 lines through the same locked, verified crontab block as the other Dot apps. The first release applied (queue line only) was missing `schedule:run`, so nothing ran the scheduler until this change; the account's crontab grows by 1 on the next deploy. Every deploy log prints `== crontab: 2 line(s) for # dot-sheet installed, M other line(s) preserved`; M must not shrink.
- The smoke check requests `/`, `/login`, `/up`, the built CSS and a harmless `POST /up`, and fails the run if any is wrong or if a debug page is served.

## Known limits

- env() is called in app code (app/Console/Commands/RunBackupCommand.php: BACKUP_RETENTION_DAYS), so caching config would make production ignore that .env value; config:cache is skipped until that call moves into config/.

## Not covered yet

PHPStan is not in CI. There is no health document for the Dot.Brain guardian, no nightly backup and no scheduler probe for Projects; the other Dot apps have all three.

## Monitoring (Dot.Brain guardian)

| What | Where | Auth |
|---|---|---|
| Scheduler liveness | `GET /up/scheduler`: 200 while the minute heartbeat is fresh, 503 when stale (and while the queue worker's five-minute heartbeat is fresh) | none |
| Guardian report | `GET /guardian/health` (`dot-guardian/v1`, platform `dot-sheet`) | `Authorization: Bearer <GUARDIAN_TOKEN>`; 503 when no token is configured, 401 when wrong |
| Alarm | `scheduler-watchdog.yml` asks `/up/scheduler` every 15 minutes (three tries) and fails the run, which GitHub emails | none |

One-time provisioning, after the release that ships this is deployed:

1. Generate a long random token and store it as the repository secret `GUARDIAN_TOKEN`.
2. Run **Provision Guardian Token** (`confirm=provision`). It writes the token to the production `.env` and refreshes the config; the value is never printed. It then calls `/guardian/health` and expects 200.
3. Enrolling the platform in Dot.Brain (registry entry and `DOT_SHEET_GUARDIAN_TOKEN`) is a separate change in that repository.

Checks in the report: database, cache, scheduler, error rate, queue backlog and worker. A missing heartbeat is `unknown`, never a claim of health.

