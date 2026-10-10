<?php

namespace App\Models;

use App\Enums\ReceivableCategory;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'customer_code',
        'receivable_category',
        'customer_name',
        'phone',
        'telephone',
        'email',
        'address',
        'no_ktp',
        'no_npwp',
        'area',
        'location_id',
        'sales_person_id',
        'credit_limit',
        'terms_of_payment_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credit_limit' => 'decimal:2',
        'receivable_category' => ReceivableCategory::class,
    ];

    // ponytail: the customers.receivable_category column already defaults to 'C' at the DB
    // level (migration), but Eloquent doesn't read DB defaults back into a freshly-created
    // in-memory model — without this, Customer::create() without receivable_category leaves
    // the attribute (and its enum cast) null until the next fetch from DB.
    protected $attributes = [
        'receivable_category' => 'C',
    ];

    public function termsOfPayment(): BelongsTo
    {
        return $this->belongsTo(TermsOfPayment::class);
    }

    public function salesPerson(): BelongsTo
    {
        return $this->belongsTo(SalesPerson::class);
    }

    /** Replaces the old free-text `area` field on the Customer form — the Warehouse master ("Location" in this app's UI). */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** The Piutang control account this customer's PV/OR transactions post to — see ReceivableCategory::accountCode(). */
    public function receivableAccountCode(): string
    {
        return $this->receivable_category->accountCode();
    }
}
