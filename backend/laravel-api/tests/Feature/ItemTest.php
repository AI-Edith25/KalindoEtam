<?php

namespace Tests\Feature;

use App\Enums\QtyCategory;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Covers Item.qty_category — the field that decides whole-number-vs-2-decimal qty for this item everywhere it's used. */
class ItemTest extends TestCase
{
    use RefreshDatabase;

    protected ItemService $itemService;
    protected ItemGroup $itemGroup;
    protected UnitOfMeasurement $uom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itemService = app(ItemService::class);
        $this->itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $this->uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
    }

    protected function baseAttributes(): array
    {
        return [
            'item_code' => 'CEM-1',
            'item_name' => 'Semen Portland 50kg',
            'item_group_id' => $this->itemGroup->id,
            'uom_id' => $this->uom->id,
            'standard_rate' => 60000,
        ];
    }

    public function test_qty_category_defaults_to_unit_when_omitted(): void
    {
        $item = $this->itemService->create($this->baseAttributes());

        $this->assertSame(QtyCategory::UNIT, $item->qty_category);
    }

    public function test_qty_category_can_be_set_to_weight_on_create(): void
    {
        $item = $this->itemService->create([...$this->baseAttributes(), 'qty_category' => 'weight']);

        $this->assertSame(QtyCategory::WEIGHT, $item->qty_category);
    }

    public function test_qty_category_can_be_changed_on_update(): void
    {
        $item = $this->itemService->create($this->baseAttributes());
        $this->assertSame(QtyCategory::UNIT, $item->qty_category);

        $item = $this->itemService->update($item, ['qty_category' => 'weight']);

        $this->assertSame(QtyCategory::WEIGHT, $item->qty_category);
    }
}
