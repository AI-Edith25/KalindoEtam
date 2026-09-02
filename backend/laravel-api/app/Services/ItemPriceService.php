<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\PriceZone;
use App\Repositories\ItemPriceRepository;
use App\Services\Import\ImportFileReader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemPriceService
{
    public function __construct(
        protected ItemPriceRepository $itemPriceRepository,
        protected AuditLogService $auditLogService,
    ) {}

    /** All overrides — small table (item x zone), no pagination. Frontend crosses this with its own Items/Price Zones lookups to render the matrix. */
    public function list(): Collection
    {
        return $this->itemPriceRepository->allWithRelations();
    }

    public function create(array $data): ItemPrice
    {
        return DB::transaction(function () use ($data) {
            $itemPrice = $this->itemPriceRepository->create($data);
            $itemPrice->load(['item', 'priceZone']);

            $this->recordPriceChange($itemPrice, null, $itemPrice->rate);

            return $itemPrice;
        });
    }

    public function update(ItemPrice $itemPrice, array $data): ItemPrice
    {
        return DB::transaction(function () use ($itemPrice, $data) {
            $oldRate = $itemPrice->rate;
            $itemPrice = $this->itemPriceRepository->update($itemPrice, $data);
            $itemPrice->load(['item', 'priceZone']);

            $this->recordPriceChange($itemPrice, $oldRate, $itemPrice->rate);

            return $itemPrice;
        });
    }

    public function delete(ItemPrice $itemPrice): void
    {
        DB::transaction(function () use ($itemPrice) {
            $itemPrice->load(['item', 'priceZone']);
            $this->recordPriceChange($itemPrice, $itemPrice->rate, null);
            $this->itemPriceRepository->delete($itemPrice);
        });
    }

    private function recordPriceChange(ItemPrice $itemPrice, ?string $oldRate, ?string $newRate): void
    {
        $this->auditLogService->record(
            'price_changed',
            'item_prices',
            "{$itemPrice->item->item_name} price in \"{$itemPrice->priceZone->name}\" changed from ".
                ($oldRate ?? 'standard rate')." to ".($newRate ?? 'standard rate').".",
            ['item_id' => $itemPrice->item_id, 'price_zone_id' => $itemPrice->price_zone_id, 'old_rate' => $oldRate, 'new_rate' => $newRate],
        );
    }

    public function export(): StreamedResponse
    {
        $rows = $this->itemPriceRepository->allWithRelations();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['item_code', 'item_name', 'zone_name', 'rate']);

            foreach ($rows as $row) {
                fputcsv($handle, [$row->item->item_code, $row->item->item_name, $row->priceZone->name, $row->rate]);
            }

            fclose($handle);
        }, 'item-prices-export.csv');
    }

    /**
     * Fixed 3-column format (item_code, zone_name, rate), header on row 1 — this is a small,
     * purpose-built bulk-update file the user controls, not an arbitrary legacy export, so
     * there's no header-row detection here unlike the Items Import Wizard. Reuses
     * ImportFileReader so both CSV and XLSX work, same as every other import in this app.
     *
     * @return array{created: int, updated: int, skipped: array<int, array{row: int, reason: string}>}
     */
    public function import(UploadedFile $file): array
    {
        $path = $file->store('imports', 'local');
        $extension = strtolower($file->getClientOriginalExtension());
        $rows = ImportFileReader::readRaw(Storage::disk('local')->path($path), $extension);
        Storage::disk('local')->delete($path);

        $header = array_map(fn ($h) => trim((string) $h), $rows[0] ?? []);
        $itemCol = array_search('item_code', $header, true);
        $zoneCol = array_search('zone_name', $header, true);
        $rateCol = array_search('rate', $header, true);

        if ($itemCol === false || $zoneCol === false || $rateCol === false) {
            return ['created' => 0, 'updated' => 0, 'skipped' => [['row' => 0, 'reason' => 'Header must include item_code, zone_name, rate.']]];
        }

        $created = 0;
        $updated = 0;
        $skipped = [];

        DB::transaction(function () use ($rows, $itemCol, $zoneCol, $rateCol, &$created, &$updated, &$skipped) {
            foreach (array_slice($rows, 1) as $i => $line) {
                $rowNumber = $i + 2;
                $itemCode = trim((string) ($line[$itemCol] ?? ''));
                $zoneName = trim((string) ($line[$zoneCol] ?? ''));
                $rate = trim((string) ($line[$rateCol] ?? ''));

                if ($itemCode === '' && $zoneName === '' && $rate === '') {
                    continue;
                }

                $item = Item::query()->where('item_code', $itemCode)->first();
                if (! $item) {
                    $skipped[] = ['row' => $rowNumber, 'reason' => "Item code \"{$itemCode}\" not found."];

                    continue;
                }

                $priceZone = PriceZone::query()->where('name', $zoneName)->first();
                if (! $priceZone) {
                    $skipped[] = ['row' => $rowNumber, 'reason' => "Price zone \"{$zoneName}\" not found."];

                    continue;
                }

                if (! is_numeric($rate)) {
                    $skipped[] = ['row' => $rowNumber, 'reason' => "Rate \"{$rate}\" is not a number."];

                    continue;
                }

                $existing = $this->itemPriceRepository->findByItemAndZone($item->id, $priceZone->id);

                if ($existing) {
                    $this->update($existing, ['rate' => $rate]);
                    $updated++;
                } else {
                    $this->create(['item_id' => $item->id, 'price_zone_id' => $priceZone->id, 'rate' => $rate]);
                    $created++;
                }
            }
        });

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }
}
