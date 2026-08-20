<?php

namespace App\Models;

use App\Enums\TaxCalculationMode;
use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tax extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'type',
        'transaction_type',
        'rate',
        'calculation_mode',
        'is_active',
    ];

    protected $casts = [
        'type' => TaxType::class,
        'transaction_type' => TaxTransactionType::class,
        'rate' => 'decimal:2',
        'calculation_mode' => TaxCalculationMode::class,
        'is_active' => 'boolean',
    ];

    /** Reverse of invoices.tax_id — used by TaxService::delete()'s referenced-by-documents guard. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Reverse of purchase_orders.tax_id — same guard, Purchase side. */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** Reverse of items.purchase_tax_id — same delete guard, Item master. */
    public function purchaseTaxItems(): HasMany
    {
        return $this->hasMany(Item::class, 'purchase_tax_id');
    }

    /** Reverse of items.sales_tax_id — same delete guard, Item master. */
    public function salesTaxItems(): HasMany
    {
        return $this->hasMany(Item::class, 'sales_tax_id');
    }

    /** Reverse of sales_order_items.tax_id — same delete guard, per-line tax. */
    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    /** Reverse of invoice_items.tax_id — same delete guard, per-line tax. */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** Reverse of purchase_order_items.tax_id — same delete guard, per-line tax. */
    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** Reverse of delivery_items.tax_id — same delete guard, per-line tax. */
    public function deliveryItems(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }
}
