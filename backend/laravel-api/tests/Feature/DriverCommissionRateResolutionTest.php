<?php

namespace Tests\Feature;

use App\Models\Fleet;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Services\DriverCommissionRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverCommissionRateResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected DriverCommissionRateService $driverCommissionRateService;

    protected Fleet $fleet;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driverCommissionRateService = app(DriverCommissionRateService::class);

        $this->fleet = Fleet::query()->create(['plat_number' => 'KT8655NV', 'default_driver_name' => 'Dani']);
        $itemGroup = ItemGroup::query()->create(['name' => 'Semen']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $this->item = Item::query()->create(['item_code' => 'SMN-1', 'item_name' => 'Semen Conch PCC 50 KG', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000]);
    }

    public function test_resolves_the_latest_rate_on_or_before_the_delivery_date(): void
    {
        $this->driverCommissionRateService->create(['fleet_id' => $this->fleet->id, 'item_id' => $this->item->id, 'rate_per_zak' => 1000, 'effective_date' => '2026-01-01']);
        $this->driverCommissionRateService->create(['fleet_id' => $this->fleet->id, 'item_id' => $this->item->id, 'rate_per_zak' => 1100, 'effective_date' => '2026-09-01']);

        $rate = $this->driverCommissionRateService->resolveRate($this->fleet->id, $this->item->id, now()->parse('2026-09-15'));

        $this->assertNotNull($rate);
        $this->assertEquals(1100, $rate->rate_per_zak);
    }

    public function test_resolves_the_earlier_rate_when_delivery_predates_the_newer_one(): void
    {
        $this->driverCommissionRateService->create(['fleet_id' => $this->fleet->id, 'item_id' => $this->item->id, 'rate_per_zak' => 1000, 'effective_date' => '2026-01-01']);
        $this->driverCommissionRateService->create(['fleet_id' => $this->fleet->id, 'item_id' => $this->item->id, 'rate_per_zak' => 1100, 'effective_date' => '2026-09-01']);

        $rate = $this->driverCommissionRateService->resolveRate($this->fleet->id, $this->item->id, now()->parse('2026-03-01'));

        $this->assertEquals(1000, $rate->rate_per_zak);
    }

    public function test_returns_null_not_an_exception_when_no_rate_has_taken_effect_yet(): void
    {
        $this->driverCommissionRateService->create(['fleet_id' => $this->fleet->id, 'item_id' => $this->item->id, 'rate_per_zak' => 1000, 'effective_date' => '2026-09-01']);

        $rate = $this->driverCommissionRateService->resolveRate($this->fleet->id, $this->item->id, now()->parse('2026-01-01'));

        $this->assertNull($rate);
    }

    public function test_returns_null_for_an_unregistered_fleet_item_pair(): void
    {
        $rate = $this->driverCommissionRateService->resolveRate($this->fleet->id, $this->item->id, now());

        $this->assertNull($rate);
    }

    public function test_adding_a_new_rate_never_replaces_the_old_one(): void
    {
        $this->driverCommissionRateService->create(['fleet_id' => $this->fleet->id, 'item_id' => $this->item->id, 'rate_per_zak' => 1000, 'effective_date' => '2026-01-01']);
        $this->driverCommissionRateService->create(['fleet_id' => $this->fleet->id, 'item_id' => $this->item->id, 'rate_per_zak' => 1100, 'effective_date' => '2026-09-01']);

        $this->assertDatabaseCount('driver_commission_rates', 2);
    }
}
