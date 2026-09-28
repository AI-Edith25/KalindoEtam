<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Http\Resources\ItemResource;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Services\ItemService;
use App\Services\UomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Item.uoms — extra UOMs with a conversion factor to the base uom_id. */
class ItemUomTest extends TestCase
{
    use RefreshDatabase;

    protected ItemService $itemService;
    protected UnitOfMeasurement $kg;
    protected UnitOfMeasurement $dus;
    protected string $groupId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itemService = app(ItemService::class);
        $this->kg = UnitOfMeasurement::query()->create(['name' => 'KG']);
        $this->dus = UnitOfMeasurement::query()->create(['name' => 'DUS']);
        $this->groupId = ItemGroup::query()->create(['name' => 'General'])->id;
    }

    protected function attrs(array $extra = []): array
    {
        return array_merge([
            'item_code' => 'PKU-1', 'item_name' => 'Paku', 'item_group_id' => $this->groupId,
            'uom_id' => $this->kg->id, 'standard_rate' => 1000,
        ], $extra);
    }

    public function test_create_with_extra_uom_persists_and_resource_lists_base_first(): void
    {
        $item = $this->itemService->create($this->attrs(['uoms' => [['uom_id' => $this->dus->id, 'conversion_factor' => 25]]]));

        $this->assertDatabaseHas('item_uoms', ['item_id' => $item->id, 'uom_id' => $this->dus->id]);

        $uoms = (new ItemResource($item->fresh(['uom', 'itemUoms.uom'])))->toArray(request())['uoms'];
        $this->assertSame([true, false], array_column($uoms, 'is_base'));
        $this->assertSame($this->kg->id, $uoms[0]['uom_id']);
        $this->assertEquals(25, $uoms[1]['conversion_factor']);
    }

    public function test_update_replaces_extras_and_omitting_uoms_leaves_them(): void
    {
        $item = $this->itemService->create($this->attrs(['uoms' => [['uom_id' => $this->dus->id, 'conversion_factor' => 25]]]));

        $this->itemService->update($item, ['item_name' => 'Paku 2']);
        $this->assertDatabaseCount('item_uoms', 1);

        $this->itemService->update($item->fresh(), ['uoms' => [['uom_id' => $this->dus->id, 'conversion_factor' => 30]]]);
        $this->assertEquals(30, $item->itemUoms()->first()->conversion_factor);
        $this->assertDatabaseCount('item_uoms', 1);

        $this->itemService->update($item->fresh(), ['uoms' => []]);
        $this->assertDatabaseCount('item_uoms', 0);
    }

    public function test_extra_uom_cannot_equal_base(): void
    {
        $this->expectException(BusinessException::class);

        $this->itemService->create($this->attrs(['uoms' => [['uom_id' => $this->kg->id, 'conversion_factor' => 1]]]));
    }

    public function test_base_uom_cannot_change_while_extras_exist(): void
    {
        $item = $this->itemService->create($this->attrs(['uoms' => [['uom_id' => $this->dus->id, 'conversion_factor' => 25]]]));
        $other = UnitOfMeasurement::query()->create(['name' => 'TON']);

        $this->expectException(BusinessException::class);
        $this->itemService->update($item->fresh(), ['uom_id' => $other->id]);
    }

    public function test_uom_used_as_extra_cannot_be_deleted(): void
    {
        $this->itemService->create($this->attrs(['uoms' => [['uom_id' => $this->dus->id, 'conversion_factor' => 25]]]));

        $this->expectException(BusinessException::class);
        app(UomService::class)->delete($this->dus);
    }
}
