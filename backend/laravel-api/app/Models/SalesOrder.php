<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'customer_id',
        'sales_person_id',
        'branch_id',
        'warehouse_id',
        'order_date',
        'expected_delivery_date',
        'total_amount',
        'tax_id',
        'tax_amount',
        'grand_total',
        'remarks',
        'attention',
        'tel',
        'fax',
        'reference',
        'terms_of_payment_id',
    ];

    protected $casts = [
        'status' => SalesOrderStatus::class,
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'total_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function documentType(): string
    {
        return 'sales';
    }

    /**
     * The old two-step ApprovalFlow dance (request approval, then a separate
     * approver decides, then the user still had to click Submit) is
     * superseded by the single "Approve" action below — SalesOrderStatus
     * collapses that into one status transition, gated by route middleware
     * (`sales.orders.approve`) instead of a pre-existing ApprovalFlow record.
     * PurchaseOrder/JournalEntry are untouched and still use ApprovalFlow.
     */
    public function requiresApproval(): bool
    {
        return false;
    }

    /** Submitted = just created, still editable — the old "Draft" meaning, renamed. */
    protected function initialStatus(): \BackedEnum
    {
        return SalesOrderStatus::SUBMITTED;
    }

    /** Approved = locked, can be delivered against — the old "Submitted" meaning, renamed. */
    protected function submittedStatus(): \BackedEnum
    {
        return SalesOrderStatus::APPROVED;
    }

    protected function cancelledStatus(): \BackedEnum
    {
        return SalesOrderStatus::CANCELLED;
    }

    /** Cancel is allowed either before or after approval — a request can be withdrawn while still awaiting review, not only once it's locked in. */
    protected function cancellableStatuses(): array
    {
        return [SalesOrderStatus::SUBMITTED, SalesOrderStatus::APPROVED];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesPerson(): BelongsTo
    {
        return $this->belongsTo(SalesPerson::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function termsOfPayment(): BelongsTo
    {
        return $this->belongsTo(TermsOfPayment::class);
    }

    /** Same tax_id/tax_amount pattern as PurchaseOrder/Invoice, reusing TaxService. */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
