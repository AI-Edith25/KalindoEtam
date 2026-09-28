<?php

namespace App\Models;

use App\Enums\QtyCategory;
use App\Exceptions\BusinessException;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'item_code',
        'item_name',
        'item_group_id',
        'uom_id',
        'standard_rate',
        'current_stock',
        'purchase_tax_id',
        'sales_tax_id',
        'allow_over_receipt',
        'qty_category',
        'sync_to_main_wh',
    ];

    protected $casts = [
        'standard_rate' => 'decimal:2',
        'current_stock' => 'decimal:4',
        'allow_over_receipt' => 'boolean',
        'qty_category' => QtyCategory::class,
        'sync_to_main_wh' => 'boolean',
    ];

    public function itemGroup(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'uom_id');
    }

    public function purchaseTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'purchase_tax_id');
    }

    public function salesTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'sales_tax_id');
    }

    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class);
    }

    public function stockLedgers(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }

    /** Extra UOMs (with factor to the base uom_id) — the base UOM itself is not a row here. */
    public function itemUoms(): HasMany
    {
        return $this->hasMany(ItemUom::class);
    }

    /**
     * The choices a line-item UOM picker offers: base UOM first (factor 1), then the extras.
     * Needs `uom` and `itemUoms.uom` eager-loaded.
     *
     * @return array<int, array<string, mixed>>
     */
    public function uomChoices(): array
    {
        $choices = [[
            'uom_id' => $this->uom_id,
            'name' => $this->uom?->name,
            'symbol' => $this->uom?->symbol,
            'conversion_factor' => '1',
            'is_base' => true,
        ]];

        foreach ($this->itemUoms as $row) {
            $choices[] = [
                'uom_id' => $row->uom_id,
                'name' => $row->uom?->name,
                'symbol' => $row->uom?->symbol,
                'conversion_factor' => $row->conversion_factor,
                'is_base' => false,
            ];
        }

        return $choices;
    }

    /**
     * Validates a document line's chosen UOM against this item and returns what the line must
     * snapshot: uom_id null = the base UOM (factor 1). Anything else must be one of itemUoms,
     * and the factor always comes from there — never from the client.
     *
     * @return array{uom_id: ?string, uom_factor: string}
     */
    public function resolveLineUom(?string $uomId): array
    {
        if ($uomId === null || $uomId === $this->uom_id) {
            return ['uom_id' => null, 'uom_factor' => '1'];
        }

        $extra = $this->itemUoms()->where('uom_id', $uomId)->first();

        if ($extra === null) {
            throw new BusinessException("UOM is not available for item {$this->item_code}.");
        }

        return ['uom_id' => $uomId, 'uom_factor' => (string) $extra->conversion_factor];
    }

    public function itemWarehousePrices(): HasMany
    {
        return $this->hasMany(ItemWarehousePrice::class);
    }
}
