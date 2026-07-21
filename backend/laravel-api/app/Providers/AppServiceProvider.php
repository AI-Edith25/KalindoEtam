<?php

namespace App\Providers;

use App\Contracts\DocumentNumberGeneratorInterface;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\ReceiptEntry;
use App\Services\DocumentNumberGeneratorService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DocumentNumberGeneratorInterface::class, DocumentNumberGeneratorService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short, stable keys for polymorphic reference_type columns (e.g.
        // journal_entries.reference_type) instead of raw class names — the
        // UI must never surface a PHP FQCN. Not enforced, so any existing
        // FQCN-stored polymorphic rows (e.g. document_timelines.subject_type
        // from before this map existed) still resolve via Eloquent's
        // default fallback. Registering a future reference type (Credit
        // Note, Expense, ...) is one line here — no Accounting Engine code
        // changes needed.
        Relation::morphMap([
            'invoice' => Invoice::class,
            'receipt_entry' => ReceiptEntry::class,
            'payment_allocation' => PaymentAllocation::class,
            'credit_note' => CreditNote::class,
            'debit_note' => DebitNote::class,
        ]);
    }
}
