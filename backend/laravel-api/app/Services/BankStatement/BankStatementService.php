<?php

namespace App\Services\BankStatement;

use App\Enums\BankStatementStatus;
use App\Models\BankStatement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BankStatementService
{
    public function __construct(
        private BankStatementParserRegistry $registry,
        private BankReconciliationService $reconciliationService,
    ) {}

    /**
     * Parses synchronously (statement files are small -- one bank/one period at a
     * time) and returns a preview without persisting BankStatementLine rows yet;
     * confirm() re-parses the stored file and persists them once the user approves
     * the preview.
     *
     * @return array{ok: bool, batch?: BankStatement, preview_rows?: array, message?: string}
     */
    public function upload(string $bankAccountId, ?string $formatTemplate, UploadedFile $file, ?string $userId): array
    {
        $rawContent = file_get_contents($file->getRealPath());

        $formatTemplate ??= $this->registry->detect($rawContent);
        if ($formatTemplate === null) {
            return ['ok' => false, 'message' => 'Could not auto-detect the bank format. Please select a Bank/Format Template manually.'];
        }

        $rows = $this->registry->get($formatTemplate)->parse($rawContent);

        $path = $file->store('bank-statements', 'local');

        $batch = BankStatement::query()->create([
            'bank_account_id' => $bankAccountId,
            'format_template' => $formatTemplate,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'status' => BankStatementStatus::UPLOADED,
            'created_by' => $userId,
        ]);

        return ['ok' => true, 'batch' => $batch, 'preview_rows' => $rows];
    }

    /** Re-parses the stored file and persists the normalized lines. */
    public function confirm(BankStatement $batch): BankStatement
    {
        $rawContent = Storage::disk($batch->disk)->get($batch->file_path);
        $rows = $this->registry->get($batch->format_template)->parse($rawContent);

        DB::transaction(function () use ($batch, $rows) {
            foreach ($rows as $row) {
                $batch->lines()->create($row);
            }

            $dates = array_column($rows, 'transaction_date');
            $batch->update([
                'status' => BankStatementStatus::PROCESSED,
                'period_start' => $dates === [] ? null : min($dates),
                'period_end' => $dates === [] ? null : max($dates),
            ]);
        });

        $batch = $batch->refresh();
        $this->reconciliationService->recomputeForStatement($batch);

        return $batch;
    }
}
