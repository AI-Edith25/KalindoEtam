<?php

namespace App\Models;

use App\Enums\DocumentStatus;
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
        'status' => DocumentStatus::class,
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

    /** Operational commitment to a customer before any Delivery/Receivable exists — see docs/APPROVAL_WORKFLOW_DESIGN.md §3. */
    public function requiresApproval(): bool
    {
        return true;
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
