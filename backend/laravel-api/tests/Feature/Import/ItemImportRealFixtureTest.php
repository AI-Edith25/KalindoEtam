<?php

namespace Tests\Feature\Import;

use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Import Wizard against a real legacy export (xlsItemListing.xlsx): 3 title/
 * marker rows, the real header at row 4, a type-spec row at row 5, then 95
 * data rows. Exposed the header-row-hardcoded-to-1 bug and the
 * numeric-Excel-cell-corrupted-by-decimal-style bug — both fixed here.
 */
class ItemImportRealFixtureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.items.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.items.import');
        Sanctum::actingAs($user);
    }

    private function fixtureFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/xlsItemListing.xlsx'),
            'xlsItemListing.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    public function test_header_and_data_rows_are_auto_detected_past_the_preamble(): void
    {
        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->fixtureFile()]);
        $upload->assertCreated();

        $this->assertSame(4, $upload->json('data.header_row'));
        $this->assertSame(6, $upload->json('data.data_start_row'));
        $this->assertSame(95, $upload->json('data.batch.total_rows'));

        // The AmendYN column and the trailing blank columns never appear as real headers once
        // detection lands on row 4 — they collapse to a blank header key, dropped as empty.
        $this->assertContains('', $upload->json('data.cleaning_report.dropped_empty_columns'));

        $this->assertSame('item_code', $upload->json('data.suggested_mapping.ItemCode'));
        $this->assertSame('item_name', $upload->json('data.suggested_mapping.Description'));
        $this->assertSame('standard_rate', $upload->json('data.suggested_mapping.UnitPrice'));
        $this->assertSame('uom_id', $upload->json('data.suggested_mapping.UOM'));

        $itemCodeSample = collect($upload->json('data.sample_rows'))->first()['ItemCode'] ?? null;
        $this->assertSame('8,5 KG_TOKKA_2"', $itemCodeSample);
    }

    public function test_manual_header_settings_override_is_respected(): void
    {
        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->fixtureFile()]);
        $batchId = $upload->json('data.batch.id');

        // Force it back to row 1 (wrong on purpose) to prove the override actually takes effect.
        $override = $this->patchJson("/api/v1/import/batches/{$batchId}/header-settings", [
            'header_row' => 1,
            'data_start_row' => 2,
        ]);
        $override->assertOk();
        $this->assertSame(1, $override->json('data.batch.header_row'));
        $this->assertContains('ITEM MAINTENANCE', $override->json('data.headers'));

        // And back to the correct settings.
        $restore = $this->patchJson("/api/v1/import/batches/{$batchId}/header-settings", [
            'header_row' => 4,
            'data_start_row' => 6,
        ]);
        $restore->assertOk();
        $this->assertSame('ItemCode', $restore->json('data.headers')[1]);
    }

    public function test_price_column_survives_as_a_genuine_excel_number_regardless_of_decimal_style(): void
    {
        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->fixtureFile()]);
        $batchId = $upload->json('data.batch.id');

        ItemGroup::query()->create(['name' => 'Umum']);

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => [
                'ItemCode' => 'item_code',
                'Description' => 'item_name',
                'UnitPrice' => 'standard_rate',
                'UOM' => 'uom_id',
            ],
            'field_defaults' => ['item_group_id' => 'Umum'],
        ])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $preview->assertOk();

        $row = collect($preview->json('data.rows'))->first(fn ($r) => $r['data']['item_code'] === 'BENDRAT TOKKA @ 20 KG');
        $this->assertNotNull($row);
        $this->assertSame(255945.95, $row['data']['standard_rate']);
        // Commas/quotes in item_name must survive untouched — normalizeText never strips them.
        $this->assertSame('KAWAT BENDRAT TOKKA @ 20 KG', $row['data']['item_name']);

        $quoteRow = collect($preview->json('data.rows'))->first(fn ($r) => $r['data']['item_code'] === '8,5 KG_TOKKA_2"');
        $this->assertNotNull($quoteRow);
        $this->assertSame('PAKU TOKKA 2"@ 8,5 KG', $quoteRow['data']['item_name']);
    }

    /**
     * Regression for a stuck-batch report: field_defaults.item_group_id = "-" combined with a
     * "skip" fk_resolution for that value applies to every row (it's a constant default, not a
     * mapped column) and item_group_id is a required, non-nullable FK — so every row must fail,
     * with a message that explains why (required + skip), not a generic "skipped" note.
     */
    public function test_skip_resolution_on_required_field_default_fails_every_row_with_a_clear_message(): void
    {
        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->fixtureFile()]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => [
                'ItemCode' => 'item_code',
                'Description' => 'item_name',
                'UnitPrice' => 'standard_rate',
                'UOM' => 'uom_id',
            ],
            'field_defaults' => ['item_group_id' => '-'],
        ])->assertOk();

        $resolutions = ['item_group_id' => ['-' => ['action' => 'skip']], 'uom_id' => []];
        foreach (array_keys($this->getJson("/api/v1/import/batches/{$batchId}/fk-candidates")->json('data.uom_id') ?? []) as $uomValue) {
            $resolutions['uom_id'][$uomValue] = ['action' => 'create'];
        }
        $this->patchJson("/api/v1/import/batches/{$batchId}/fk-resolutions", ['resolutions' => $resolutions])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $preview->assertOk();
        $summary = $preview->json('data.summary');
        $this->assertSame(95, $summary['total']);
        $this->assertSame(0, $summary['valid']);
        $this->assertSame(95, $summary['error']);

        $firstRow = $preview->json('data.rows')[0];
        $this->assertSame('error', $firstRow['status']);
        $this->assertStringContainsString('is required', implode('; ', $firstRow['messages']));
        $this->assertStringContainsString('Skip', implode('; ', $firstRow['messages']));
    }

    public function test_full_commit_with_a_default_group_value_and_resolved_uoms(): void
    {
        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->fixtureFile()]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => [
                'ItemCode' => 'item_code',
                'Description' => 'item_name',
                'UnitPrice' => 'standard_rate',
                'UOM' => 'uom_id',
            ],
            'field_defaults' => ['item_group_id' => 'Umum'],
        ])->assertOk();

        $candidates = $this->getJson("/api/v1/import/batches/{$batchId}/fk-candidates");
        $candidates->assertOk();

        // Group's default value has no existing master yet -> auto-create; every distinct UOM
        // value in the file also needs a resolution (auto-create whatever isn't seeded).
        $resolutions = ['item_group_id' => ['Umum' => ['action' => 'create']], 'uom_id' => []];
        foreach (array_keys($candidates->json('data.uom_id') ?? []) as $uomValue) {
            $resolutions['uom_id'][$uomValue] = ['action' => 'create'];
        }

        $this->patchJson("/api/v1/import/batches/{$batchId}/fk-resolutions", ['resolutions' => $resolutions])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $preview->assertOk();
        $summary = $preview->json('data.summary');
        $this->assertSame(95, $summary['total']);
        $this->assertSame(0, $summary['error']);

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertSame(95, Item::query()->count());

        $group = ItemGroup::query()->where('name', 'Umum')->first();
        $this->assertNotNull($group);
        $this->assertTrue(Item::query()->where('item_group_id', '!=', $group->id)->doesntExist());

        // Stock was never in the mapping — every created item still lands at the DB default (0).
        $this->assertTrue(Item::query()->where('current_stock', '!=', 0)->doesntExist());

        $item = Item::query()->where('item_code', 'BENDRAT TOKKA @ 20 KG')->first();
        $this->assertSame('255945.95', $item->standard_rate);
    }
}
