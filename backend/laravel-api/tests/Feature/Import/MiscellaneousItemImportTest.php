<?php

namespace Tests\Feature\Import;

use App\Models\ChartOfAccount;
use App\Models\MiscellaneousItem;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Import Wizard — Miscellaneous module. charge_type is derived from the legacy
 * Plus_MinusYN column (0/1/2/3 -> addition/deduction/addition_percent/deduction_percent,
 * confirmed assumption — see MiscellaneousItemImportTemplate's docblock). uom_id/
 * sales_account_id/purchase_account_id are all optional FKs — unresolved values null out
 * and only warn, never fail the row.
 */
class MiscellaneousItemImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.miscellaneous.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.miscellaneous.import');
        Sanctum::actingAs($user);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function mapping(): array
    {
        return [
            'mapping' => [
                'OCCode' => 'misc_code',
                'Description' => 'description',
                'R_ate' => 'rate',
                'UnitCost' => 'unit_cost',
                'UOM' => 'uom_id',
                'GLCode' => 'sales_account_id',
                'PurchaseAccount' => 'purchase_account_id',
                '_charge_type' => 'charge_type',
            ],
        ];
    }

    public function test_plus_minus_yn_maps_to_all_four_charge_types(): void
    {
        UnitOfMeasurement::query()->create(['name' => 'Ton']);
        ChartOfAccount::query()->create(['code' => '411.01.01', 'name' => 'Freight Income', 'account_type' => 'revenue']);

        $csv = "OCCode,Description,R_ate,UnitCost,UOM,GLCode,PurchaseAccount,Plus_MinusYN\n"
            ."ANGKUT,Ongkos Angkut,0,0,Ton,411.01.01,411.01.01,0\n"
            ."DISCOUNT,Discount,0,0,,411.01.01,411.01.01,1\n"
            ."PPH23,PPH Pasal 23,2,0,,411.01.01,411.01.01,3\n"
            ."PPH23INCL,PPH Incl,0,0,,411.01.01,411.01.01,2\n";

        $upload = $this->post('/api/v1/import/miscellaneous/batches', ['file' => $this->csvFile('misc.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 4, 'valid' => 4, 'warning' => 0, 'error' => 0], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertSame('addition', MiscellaneousItem::query()->where('misc_code', 'ANGKUT')->first()->charge_type->value);
        $this->assertSame('deduction', MiscellaneousItem::query()->where('misc_code', 'DISCOUNT')->first()->charge_type->value);
        $this->assertSame('deduction_percent', MiscellaneousItem::query()->where('misc_code', 'PPH23')->first()->charge_type->value);
        $this->assertSame('addition_percent', MiscellaneousItem::query()->where('misc_code', 'PPH23INCL')->first()->charge_type->value);

        $angkut = MiscellaneousItem::query()->where('misc_code', 'ANGKUT')->first();
        $this->assertSame('Ton', $angkut->uom->name);
        $this->assertSame('411.01.01', $angkut->salesAccount->code);
        $this->assertSame('411.01.01', $angkut->purchaseAccount->code);
    }

    /**
     * sales_account_id/purchase_account_id are NOT NULL in the DB (see the template's
     * docblock) — an unresolved GLCode/PurchaseAccount must fail the row, it cannot be
     * left null like uom_id can.
     */
    public function test_unresolved_required_accounts_fail_the_row(): void
    {
        $csv = "OCCode,Description,R_ate,UnitCost,UOM,GLCode,PurchaseAccount,Plus_MinusYN\n"
            ."NOACCT,No Account,0,0,,DOES-NOT-EXIST,ALSO-MISSING,0\n";

        $upload = $this->post('/api/v1/import/miscellaneous/batches', ['file' => $this->csvFile('misc.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 1, 'valid' => 0, 'warning' => 0, 'error' => 1], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertNull(MiscellaneousItem::query()->where('misc_code', 'NOACCT')->first());
    }

    /** uom_id, unlike the two account fields, genuinely is nullable — an unresolved UOM only warns. */
    public function test_unresolved_optional_uom_nulls_and_only_warns(): void
    {
        ChartOfAccount::query()->create(['code' => '411.01.01', 'name' => 'Freight Income', 'account_type' => 'revenue']);

        $csv = "OCCode,Description,R_ate,UnitCost,UOM,GLCode,PurchaseAccount,Plus_MinusYN\n"
            ."NOUOM,No Uom,0,0,DOES-NOT-EXIST,411.01.01,411.01.01,0\n";

        $upload = $this->post('/api/v1/import/miscellaneous/batches', ['file' => $this->csvFile('misc.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 1, 'valid' => 0, 'warning' => 1, 'error' => 0], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $item = MiscellaneousItem::query()->where('misc_code', 'NOUOM')->first();
        $this->assertNotNull($item);
        $this->assertNull($item->uom_id);
    }

    public function test_missing_required_description_skips_only_that_row(): void
    {
        ChartOfAccount::query()->create(['code' => '411.01.01', 'name' => 'Freight Income', 'account_type' => 'revenue']);

        $csv = "OCCode,Description,R_ate,UnitCost,UOM,GLCode,PurchaseAccount,Plus_MinusYN\n"
            ."BAD,,0,0,,411.01.01,411.01.01,0\n"
            ."GOOD,Has Description,0,0,,411.01.01,411.01.01,0\n";

        $upload = $this->post('/api/v1/import/miscellaneous/batches', ['file' => $this->csvFile('misc.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 2, 'valid' => 1, 'warning' => 0, 'error' => 1], $preview->json('data.summary'));
    }
}
