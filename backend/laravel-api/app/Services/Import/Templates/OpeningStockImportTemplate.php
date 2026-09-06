<?php

namespace App\Services\Import\Templates;

use App\Models\Item;
use App\Models\OpeningStock;
use App\Models\Warehouse;
use App\Services\Import\Contracts\CreatesRelatedRecords;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;
use App\Services\OpeningStockService;
use Illuminate\Support\Str;

/**
 * Bulk entry for Opening Stock — the one import target in this app whose row is a whole new
 * document (header + one line item), not a single flat record, so it implements
 * CreatesRelatedRecords instead of the generic $model::updateOrCreate() path every other
 * template uses (see ProcessImportBatchJob and that interface's docblock). persist() delegates
 * to OpeningStockService::create() — the exact same validation (qty_category, cutoff date
 * precedes existing activity, non-negative cost) the manual "New Opening Stock" page runs,
 * never reimplemented here. Every imported document lands as a Draft, tagged with the
 * ImportBatch's own id — the list page's "Submit All"/"Cancel All" then act on that whole
 * batch at once (see OpeningStockService::submitBatch()/cancelBatch()).
 *
 * uniqueKeyField() points at a synthetic per-row marker (`row_marker`, always a fresh UUID),
 * not a real business key — unlike every other template, the same item legitimately appears
 * on multiple rows here (different warehouse/date/cost = a different layer), so there is no
 * meaningful "duplicate" to detect the way ImportBatchService::buildCleanedRows() otherwise
 * checks for one.
 */
final class OpeningStockImportTemplate implements ImportTemplate, CreatesRelatedRecords
{
    public function key(): string
    {
        return 'opening-stock';
    }

    public function label(): string
    {
        return 'Opening Stock';
    }

    public function fields(): array
    {
        return [
            new ImportFieldDefinition(
                name: 'item_id',
                label: 'Item Code',
                type: 'fk',
                required: true,
                fkTarget: ['model' => Item::class, 'displayColumn' => 'item_code'],
                synonyms: ['item code', 'item_code', 'code', 'kode barang'],
                example: 'ITM001',
            ),
            new ImportFieldDefinition(
                name: 'warehouse_id',
                label: 'Warehouse',
                type: 'fk',
                required: true,
                fkTarget: ['model' => Warehouse::class, 'displayColumn' => 'code'],
                synonyms: ['warehouse', 'gudang', 'warehouse code'],
                example: 'SMD',
            ),
            new ImportFieldDefinition(
                name: 'cutoff_date',
                label: 'Date',
                type: 'date',
                required: true,
                synonyms: ['date', 'tanggal', 'cutoff date'],
                example: '2026-01-01',
            ),
            new ImportFieldDefinition(
                name: 'qty',
                label: 'Qty',
                type: 'number',
                required: true,
                synonyms: ['qty', 'quantity', 'jumlah'],
                example: '100',
            ),
            new ImportFieldDefinition(
                name: 'unit_cost',
                label: 'Unit Cost',
                type: 'number',
                required: true,
                synonyms: ['unit cost', 'unitcost', 'harga', 'cost'],
                example: '58000',
            ),
            new ImportFieldDefinition(
                name: 'row_marker',
                label: 'Row Marker',
                type: 'string',
                synonyms: [],
                example: '',
                autoMapFrom: '_row_marker',
            ),
        ];
    }

    public function model(): string
    {
        return OpeningStock::class;
    }

    public function uniqueKeyField(): string
    {
        return 'row_marker';
    }

    public function validationRules(array $row, array $context): array
    {
        return [
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'cutoff_date' => ['required', 'date'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function transformRow(array $row): array
    {
        $row['_row_marker'] = (string) Str::uuid();

        return $row;
    }

    public function persist(array $data, string $importBatchId): void
    {
        $openingStock = app(OpeningStockService::class)->create([
            'warehouse_id' => $data['warehouse_id'],
            'cutoff_date' => $data['cutoff_date'],
            'items' => [['item_id' => $data['item_id'], 'qty' => $data['qty'], 'unit_cost' => $data['unit_cost']]],
        ]);

        $openingStock->update(['import_batch_id' => $importBatchId]);
    }
}
