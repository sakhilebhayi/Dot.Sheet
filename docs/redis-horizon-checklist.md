# Redis + Horizon Deployment Checklist

> **Not used in production.** Production runs on a cPanel shared host with SQLite, the database queue and no Redis or websocket server (`QUEUE_CONNECTION=database`, `BROADCAST_CONNECTION=null`). Horizon is not a dependency of this repo. Keep this page only for an environment that deliberately adds Redis and Horizon.

Use this checklist when preparing a production deployment that relies on queued jobs and realtime collaboration.

## Redis

- [ ] Install Redis server and ensure persistence is configured (AOF and/or RDB snapshots).
- [ ] Restrict Redis network access (private network and/or firewall rules).
- [ ] Set `REDIS_HOST`, `REDIS_PORT`, and `REDIS_PASSWORD` in production environment.
- [ ] Verify queue/cache/session connectivity:

```bash
php artisan tinker --execute="cache()->put('health', 'ok', 60); echo cache()->get('health');"
php artisan queue:monitor redis:default --max=100
```

## Horizon

- [ ] Confirm `QUEUE_CONNECTION=redis` in production env.
- [ ] Publish Horizon config once and commit `config/horizon.php` if customized.
- [ ] Start Horizon via Supervisor using [deploy/supervisor/horizon.conf.example](../deploy/supervisor/horizon.conf.example).
- [ ] Validate Horizon process is healthy:

```bash
php artisan horizon:status
php artisan horizon:supervisor-status
```

## Supervisor

- [ ] Copy [deploy/supervisor/horizon.conf.example](../deploy/supervisor/horizon.conf.example) to `/etc/supervisor/conf.d/dotsheet-horizon.conf`.
- [ ] Optionally copy [deploy/supervisor/queue-worker.conf.example](../deploy/supervisor/queue-worker.conf.example) for additional workers.
- [ ] Reload supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

## Post-Deploy Verification

- [ ] Run import/export smoke tests.
- [ ] Verify batch progress updates in UI for long-running imports.
- [ ] Confirm failed jobs are visible and retryable from Horizon dashboard.
- [ ] Confirm queue lag is below acceptable threshold.
