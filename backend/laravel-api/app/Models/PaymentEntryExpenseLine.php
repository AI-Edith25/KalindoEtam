<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One general-expense-purpose line inside a payment_type=mixed PaymentEntry — the expense-side
 * counterpart to PaymentEntryAllocation (the supplier-bill-side line), kept as its own table
 * rather than unified with it so the AP settlement/outstanding-amount machinery never has to
 * know this table exists. Not Documentable, same reasoning as PaymentEntryAllocation: it's a
 * fact recorded once at PaymentEntryService::submit() time, never independently drafted or
 * numbered. Has no journalLines() of its own — unlike an allocation, an expense line is never
 * posted as its own journal entry; it only ever contributes one debit leg to the single
 * aggregate journal PaymentEntry::journalLines() builds for the whole mixed voucher, which is
 * also why it has no reversal path (reversing one would mean reversing that whole journal).
 */
class PaymentEntryExpenseLine extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'payment_entry_id',
        'line_no',
        'expense_account_id',
        'description',
        'branch_id',
        'amount',
        'notes',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function paymentEntry(): BelongsTo
    {
        return $this->belongsTo(PaymentEntry::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
