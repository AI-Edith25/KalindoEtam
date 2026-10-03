<?php

namespace Tests\Feature;

use App\Enums\MiscellaneousChargeType;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit sweep after ChartOfAccountSoftDeletedCodeReuseTest / SupplierService's own prior fix:
 * every master-data table with a plain DB-level unique() column + SoftDeletes has the same
 * "ghost uniqueness" footgun — a soft-deleted row still occupies its code forever as far as the
 * database is concerned, even though the list/search UI already hides it. Each Store*Request's
 * own whereNull('deleted_at') comment and each Service::create()'s own restore-instead-of-insert
 * comment explain the two-part fix; this is the one-test-per-table confirmation it actually works
 * end to end (validation + the real DB unique index), not just that validation passes.
 */
class MasterDataSoftDeletedCodeReuseTest extends TestCase
{
    use RefreshDatabase;

    private function actingUserWithPermission(string $permission): void
    {
        Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user);
    }

    public function test_item_group(): void
    {
        $this->actingUserWithPermission('master.item_groups.create');
        \App\Models\ItemGroup::query()->create(['name' => 'Semen'])->delete();

        $response = $this->postJson('/api/v1/item-groups', ['name' => 'Semen']);

        $response->assertCreated();
        $this->assertSame('Semen', $response->json('data.name'));
    }

    public function test_uom(): void
    {
        $this->actingUserWithPermission('master.uoms.create');
        \App\Models\UnitOfMeasurement::query()->create(['name' => 'ZAK'])->delete();

        $response = $this->postJson('/api/v1/uoms', ['name' => 'ZAK']);

        $response->assertCreated();
        $this->assertSame('ZAK', $response->json('data.name'));
    }

    public function test_item(): void
    {
        $this->actingUserWithPermission('master.items.create');
        $itemGroup = \App\Models\ItemGroup::query()->create(['name' => 'Semen']);
        $uom = \App\Models\UnitOfMeasurement::query()->create(['name' => 'ZAK']);
        \App\Models\Item::query()->create([
            'item_code' => 'SC-001', 'item_name' => 'Old', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id,
        ])->delete();

        $response = $this->postJson('/api/v1/items', [
            'item_code' => 'SC-001', 'item_name' => 'Semen Conch 50KG', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id,
        ]);

        $response->assertCreated();
        $this->assertSame('SC-001', $response->json('data.item_code'));
    }

    public function test_company(): void
    {
        $this->actingUserWithPermission('administration.company.create');
        Company::query()->create(['name' => 'Old', 'code' => 'KE', 'fiscal_year_start' => '2026-01-01'])->delete();

        $response = $this->postJson('/api/v1/companies', ['name' => 'PT Kalindo Etam', 'code' => 'KE', 'fiscal_year_start' => '2026-01-01']);

        $response->assertCreated();
        $this->assertSame('KE', $response->json('data.code'));
    }

    public function test_branch(): void
    {
        $this->actingUserWithPermission('administration.branch.create');
        $company = Company::query()->create(['name' => 'PT Kalindo Etam', 'code' => 'KE', 'fiscal_year_start' => '2026-01-01']);
        \App\Models\Branch::query()->create(['company_id' => $company->id, 'name' => 'Old', 'code' => 'SMD'])->delete();

        $response = $this->postJson('/api/v1/branches', ['company_id' => $company->id, 'name' => 'Samarinda', 'code' => 'SMD']);

        $response->assertCreated();
        $this->assertSame('SMD', $response->json('data.code'));
    }

    public function test_warehouse(): void
    {
        $this->actingUserWithPermission('master.warehouses.create');
        \App\Models\Warehouse::query()->create(['name' => 'Old', 'code' => 'WH1', 'warehouse_type' => 'transit'])->delete();

        $response = $this->postJson('/api/v1/warehouses', ['name' => 'Gudang Transit', 'code' => 'WH1', 'warehouse_type' => 'transit']);

        $response->assertCreated();
        $this->assertSame('WH1', $response->json('data.code'));
    }

    public function test_currency(): void
    {
        $this->actingUserWithPermission('master.currencies.create');
        \App\Models\Currency::query()->create(['code' => 'USD', 'name' => 'Old Dollar'])->delete();

        $response = $this->postJson('/api/v1/currencies', ['code' => 'USD', 'name' => 'US Dollar']);

        $response->assertCreated();
        $this->assertSame('USD', $response->json('data.code'));
    }

    public function test_customer(): void
    {
        $this->actingUserWithPermission('master.customers.create');
        \App\Models\Customer::query()->create(['customer_code' => 'C-0099', 'customer_name' => 'Old'])->delete();

        $response = $this->postJson('/api/v1/customers', ['customer_code' => 'C-0099', 'customer_name' => 'Toko Baru']);

        $response->assertCreated();
        $this->assertSame('C-0099', $response->json('data.customer_code'));
    }

    public function test_supplier(): void
    {
        $this->actingUserWithPermission('master.suppliers.create');
        \App\Models\Supplier::query()->create(['supplier_code' => 'S-0099', 'supplier_name' => 'Old'])->delete();

        $response = $this->postJson('/api/v1/suppliers', ['supplier_code' => 'S-0099', 'supplier_name' => 'Supplier Baru']);

        $response->assertCreated();
        $this->assertSame('S-0099', $response->json('data.supplier_code'));
    }

    public function test_sales_person(): void
    {
        $this->actingUserWithPermission('master.sales_persons.create');
        \App\Models\SalesPerson::query()->create(['code' => 'SP-01', 'name' => 'Old'])->delete();

        $response = $this->postJson('/api/v1/sales-persons', ['code' => 'SP-01', 'name' => 'Akhsan']);

        $response->assertCreated();
        $this->assertSame('SP-01', $response->json('data.code'));
    }

    public function test_terms_of_payment(): void
    {
        $this->actingUserWithPermission('master.terms_of_payment.create');
        \App\Models\TermsOfPayment::query()->create(['code' => 'NET30', 'name' => 'Old', 'days' => 30])->delete();

        $response = $this->postJson('/api/v1/terms-of-payments', ['code' => 'NET30', 'name' => '30 Days', 'days' => 30]);

        $response->assertCreated();
        $this->assertSame('NET30', $response->json('data.code'));
    }

    public function test_miscellaneous_item(): void
    {
        $this->actingUserWithPermission('master.miscellaneous.create');
        $sales = ChartOfAccount::query()->create(['code' => '4001', 'name' => 'Sales', 'account_type' => 'revenue']);
        $purchase = ChartOfAccount::query()->create(['code' => '5001', 'name' => 'COGS', 'account_type' => 'expense']);
        \App\Models\MiscellaneousItem::query()->create([
            'misc_code' => 'TRANSPORT', 'description' => 'Old', 'charge_type' => MiscellaneousChargeType::ADDITION,
            'sales_account_id' => $sales->id, 'purchase_account_id' => $purchase->id,
        ])->delete();

        $response = $this->postJson('/api/v1/miscellaneous-items', [
            'misc_code' => 'TRANSPORT', 'description' => 'Ongkos Angkut', 'charge_type' => 'addition',
            'sales_account_id' => $sales->id, 'purchase_account_id' => $purchase->id,
        ]);

        $response->assertCreated();
        $this->assertSame('TRANSPORT', $response->json('data.misc_code'));
    }
}
