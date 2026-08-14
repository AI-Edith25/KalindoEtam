<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\MiscellaneousChargeType;
use App\Http\Requests\StoreMiscellaneousItemRequest;
use App\Models\ChartOfAccount;
use App\Models\UnitOfMeasurement;
use App\Services\MiscellaneousItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiscellaneousItemTest extends TestCase
{
    use RefreshDatabase;

    protected MiscellaneousItemService $miscellaneousItemService;
    protected ChartOfAccount $salesAccount;
    protected ChartOfAccount $purchaseAccount;
    protected UnitOfMeasurement $uom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->miscellaneousItemService = app(MiscellaneousItemService::class);
        $this->salesAccount = ChartOfAccount::query()->create(['code' => '4900', 'name' => 'Misc Sales Income', 'account_type' => AccountType::REVENUE]);
        $this->purchaseAccount = ChartOfAccount::query()->create(['code' => '5900', 'name' => 'Misc Purchase Expense', 'account_type' => AccountType::EXPENSE]);
        $this->uom = UnitOfMeasurement::query()->create(['name' => 'Trip']);
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'misc_code' => 'MISC001',
            'description' => 'Handling Fee',
            'rate' => 10000,
            'uom_id' => $this->uom->id,
            'charge_type' => MiscellaneousChargeType::ADDITION->value,
            'unit_cost' => 5000,
            'sales_account_id' => $this->salesAccount->id,
            'purchase_account_id' => $this->purchaseAccount->id,
        ], $overrides);
    }

    public function test_create_persists_a_miscellaneous_item_with_the_selected_accounts_and_charge_type(): void
    {
        $item = $this->miscellaneousItemService->create($this->payload());

        $this->assertEquals('MISC001', $item->misc_code);
        $this->assertEquals(MiscellaneousChargeType::ADDITION, $item->charge_type);
        $this->assertEquals($this->salesAccount->id, $item->sales_account_id);
        $this->assertEquals($this->purchaseAccount->id, $item->purchase_account_id);
        $this->assertDatabaseHas('miscellaneous_items', ['misc_code' => 'MISC001']);
    }

    public function test_a_duplicate_misc_code_fails_store_validation(): void
    {
        $this->miscellaneousItemService->create($this->payload());

        $validator = validator($this->payload(['description' => 'Another Fee']), (new StoreMiscellaneousItemRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('misc_code', $validator->errors()->toArray());
    }

    public function test_update_changes_the_charge_type(): void
    {
        $item = $this->miscellaneousItemService->create($this->payload());

        $updated = $this->miscellaneousItemService->update($item, ['charge_type' => MiscellaneousChargeType::DEDUCTION_PERCENT->value]);

        $this->assertEquals(MiscellaneousChargeType::DEDUCTION_PERCENT, $updated->charge_type);
    }

    public function test_delete_soft_deletes_the_item(): void
    {
        $item = $this->miscellaneousItemService->create($this->payload());

        $this->miscellaneousItemService->delete($item);

        $this->assertSoftDeleted('miscellaneous_items', ['id' => $item->id]);
    }
}
