# Deployment Runbook

> **Production is a cPanel shared host: SQLite, PHP 8.4, no Redis, no Node, no websocket server; cron runs `schedule:run` and `queue:work`.** The authoritative procedure is [deployment-cpanel.md](deployment-cpanel.md). This page is a generic baseline; the Redis, Horizon, Supervisor and MySQL/Postgres items below do not apply to production.

This runbook provides a practical baseline for production deployment and operations.

## 1. Pre-Deployment Checklist

- [ ] Fill out production values in [.env.production.example](../.env.production.example) and copy to `.env` on server.
- [ ] Ensure app key exists and is not rotated unexpectedly.
- [ ] Ensure database credentials and network access are valid.
- [ ] (Only for a Redis-based environment, not production.) Ensure Redis is reachable from the app host.
- [ ] Install PHP extensions required by the app (`pdo_sqlite`, `mbstring`, `xml`, `zip`).

## 2. Release Steps

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

## 3. Queue and Realtime Services

- Use checklist in [docs/redis-horizon-checklist.md](redis-horizon-checklist.md).
- Install Supervisor configs from [deploy/supervisor/horizon.conf.example](../deploy/supervisor/horizon.conf.example) and [deploy/supervisor/queue-worker.conf.example](../deploy/supervisor/queue-worker.conf.example).
- Restart services after deploy:

```bash
sudo supervisorctl restart dotsheet-horizon:*
sudo supervisorctl restart dotsheet-queue:*
php artisan horizon:terminate
```

## 4. Backup Strategy

- Backup command: `php artisan ops:backup`
- Backup script: [scripts/run-backup.sh](../scripts/run-backup.sh)
- Backups are stored in `storage/app/backups/<timestamp>/`.
- Configure retention with `BACKUP_RETENTION_DAYS` or `--retention-days`.

Recommended cron (daily at 02:15):

```cron
15 2 * * * cd /var/www/dotsheet && ./scripts/run-backup.sh >> storage/logs/backup.log 2>&1
```

## 5. Post-Deploy Smoke Tests

```bash
php artisan test --filter=Spreadsheet
php artisan queue:failed
php artisan horizon:status
```

Manual checks:
- [ ] Open spreadsheet list and create a sheet.
- [ ] Edit a few cells and confirm autosave.
- [ ] Run CSV import and verify batch progress indicator updates.
- [ ] Export spreadsheet and confirm generated file is downloadable.
- [ ] Verify sharing link and team permissions still work.

## 6. Rollback

If release health checks fail:

```bash
git checkout <previous-stable-tag-or-commit>
composer install --no-dev --optimize-autoloader
npm run build
php artisan migrate:status
php artisan config:cache
sudo supervisorctl restart dotsheet-horizon:*
sudo supervisorctl restart dotsheet-queue:*
```

If data corruption is suspected, restore from the latest verified backup before reopening write traffic.
