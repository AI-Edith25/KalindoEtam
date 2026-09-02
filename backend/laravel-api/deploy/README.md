# Deploy notes

## Queue worker

Queued jobs (import commits, etc.) need a persistent `queue:work` process — nothing starts one
automatically.

**On this VPS, `supervisor` isn't installed.** Use the systemd unit instead
(`laravel-worker.service`) — systemd is already on the box, no new package needed. If you'd
rather use Supervisor, `apt install supervisor` first, then see `queue-worker.supervisor.conf`.

Install the systemd service (edit the `User=` and `WorkingDirectory=` lines first if your path
or user differs from `edithclinic` / `~/apps/KalindoEtam/backend/laravel-api`, and confirm the
`php` path with `which php` — swap `ExecStart=`'s `/usr/bin/php` if it differs):

```
sudo cp deploy/laravel-worker.service /etc/systemd/system/laravel-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now laravel-worker
sudo systemctl status laravel-worker
```

After any deploy that changes code used by a queued job, reload workers so they run the new code
instead of a stale copy already loaded in memory:

```
php artisan queue:restart
```

If a batch gets stuck at `queued` (worker down, or was down when it was dispatched), recover it
without waiting on the queue:

```
php artisan import:process-batch {batchId}
```
