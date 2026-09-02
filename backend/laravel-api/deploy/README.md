# Deploy notes

## Queue worker

Queued jobs (import commits, etc.) need a persistent `queue:work` process — nothing starts one
automatically. See `queue-worker.supervisor.conf` for an example Supervisor unit and install steps.

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
