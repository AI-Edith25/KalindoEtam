<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportItemGroupAssignmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createItem(string $code, ?ItemGroup $group = null): Item
    {
        $group ??= ItemGroup::query()->create(['name' => 'Unassigned']);
        $uom = UnitOfMeasurement::query()->firstOrCreate(['name' => 'Pcs']);

        return Item::query()->create([
            'item_code' => $code,
            'item_name' => 'Name for '.$code,
            'item_group_id' => $group->id,
            'uom_id' => $uom->id,
            'standard_rate' => 12345,
        ]);
    }

    /** Builds a file matching the real legacy export shape: headers on row 4, data from row 6. */
    private function buildFixture(array $pairs): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ItemMaintenance');
        $sheet->setCellValue('A1', 'ITEM MAINTENANCE');
        $sheet->setCellValue('A3', 'AmendYN');
        $sheet->setCellValue('B4', 'ItemCode');
        $sheet->setCellValue('C4', 'ItemGroup');
        $sheet->setCellValue('B5', '40 (t)');
        $sheet->setCellValue('C5', '40 (t)');

        $row = 6;
        foreach ($pairs as [$code, $group]) {
            $sheet->setCellValue("B{$row}", $code);
            $sheet->setCellValue("C{$row}", $group);
            $row++;
        }

        $path = storage_path('app/test-'.Str::random(8).'.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_updates_item_group_id_without_touching_other_item_columns(): void
    {
        $existingGroup = ItemGroup::query()->create(['name' => 'PAKU']);
        $item = $this->createItem('ITM-001');

        $path = $this->buildFixture([['ITM-001', 'PAKU']]);

        $this->artisan('items:import-item-groups', ['path' => $path])->assertExitCode(0);

        $item->refresh();
        $this->assertSame($existingGroup->id, $item->item_group_id);
        $this->assertSame('Name for ITM-001', $item->item_name);
        $this->assertEquals(12345, $item->standard_rate);
    }

    public function test_creates_a_missing_item_group_by_name(): void
    {
        $item = $this->createItem('ITM-002');
        $path = $this->buildFixture([['ITM-002', 'BRAND NEW GROUP']]);

        $this->artisan('items:import-item-groups', ['path' => $path])->assertExitCode(0);

        $group = ItemGroup::query()->where('name', 'BRAND NEW GROUP')->first();
        $this->assertNotNull($group);
        $this->assertSame($group->id, $item->refresh()->item_group_id);
    }

    public function test_matches_an_existing_group_case_insensitively_instead_of_duplicating_it(): void
    {
        $group = ItemGroup::query()->create(['name' => 'Semen']);
        $item = $this->createItem('ITM-003');
        $path = $this->buildFixture([['ITM-003', 'SEMEN']]);

        $this->artisan('items:import-item-groups', ['path' => $path])->assertExitCode(0);

        $this->assertSame(1, ItemGroup::query()->where('name', 'Semen')->count());
        $this->assertSame($group->id, $item->refresh()->item_group_id);
    }

    public function test_restores_a_soft_deleted_group_instead_of_duplicating_it(): void
    {
        $group = ItemGroup::query()->create(['name' => 'CAT']);
        $group->delete();
        $item = $this->createItem('ITM-005');
        $path = $this->buildFixture([['ITM-005', 'CAT']]);

        $this->artisan('items:import-item-groups', ['path' => $path])->assertExitCode(0);

        $this->assertSame(1, ItemGroup::withTrashed()->where('name', 'CAT')->count());
        $this->assertNull($group->refresh()->deleted_at);
        $this->assertSame($group->id, $item->refresh()->item_group_id);
    }

    public function test_leaves_an_unmatched_item_code_untouched(): void
    {
        $path = $this->buildFixture([['NO-SUCH-CODE', 'PAKU']]);

        $this->artisan('items:import-item-groups', ['path' => $path])->assertExitCode(0);

        $this->assertSame(0, Item::query()->where('item_code', 'NO-SUCH-CODE')->count());
    }

    public function test_dry_run_saves_nothing(): void
    {
        $group = ItemGroup::query()->create(['name' => 'PAKU']);
        $item = $this->createItem('ITM-004');
        $path = $this->buildFixture([['ITM-004', 'PAKU']]);

        $this->artisan('items:import-item-groups', ['path' => $path, '--dry-run' => true])->assertExitCode(0);

        $this->assertNotSame($group->id, $item->refresh()->item_group_id);
    }
}
