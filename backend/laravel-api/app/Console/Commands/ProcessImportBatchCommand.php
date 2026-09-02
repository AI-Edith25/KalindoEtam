<?php

namespace App\Console\Commands;

use App\Jobs\ProcessImportBatchJob;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs a QUEUED import batch's commit pipeline synchronously, in-process —
 * for recovering a batch stuck behind a dead/absent queue worker, and for
 * local testing without one. Reuses ProcessImportBatchJob::handle() itself
 * (constructed, not dispatched) so there is exactly one pipeline implementation.
 */
class ProcessImportBatchCommand extends Command
{
    protected $signature = 'import:process-batch {batchId : The import_batches.id UUID}';

    protected $description = 'Synchronously run a queued import batch\'s commit pipeline (recovery/testing, bypasses the queue).';

    public function handle(): int
    {
        $job = new ProcessImportBatchJob($this->argument('batchId'));

        try {
            app()->call([$job, 'handle']);
        } catch (Throwable $e) {
            $job->failed($e);
            $this->error("Batch failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Batch processed.');

        return self::SUCCESS;
    }
}
