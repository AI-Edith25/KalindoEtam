<?php

namespace App\Services;

use App\Models\Item;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Repositories\StockLedgerRepository;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Reports > Inventory Stock > Ledger tab's Export button. Two sheets:
 *
 * - Detail: searchAll()'s rows exactly as filtered on screen (voucher_type/search included),
 *   flattened 1:1 — see detailRows().
 * - Summary: Location -> Item Group -> Item, each item seeded with a Brought-Forward opening
 *   balance and a running balance through the filtered period — deliberately NOT filtered by
 *   voucher_type/search (only Location/Item/Date), so the running balance always rolls forward to
 *   a real closing balance instead of having transactions silently missing from it. See
 *   summaryRows() and the confirmed behavior in the approved plan
 *   (structured-wobbling-squirrel.md).
 *
 * BALANCE VALUE (both sheets, every row) prices the running qty at *today's* weighted-average
 * cost, same convention as StockLedgerService::attachCostInfo() — this ledger never stored a
 * per-row cost snapshot, so a true point-in-time value isn't reconstructable. Per-transaction
 * UNIT COST/VALUE IN/VALUE OUT *are* exact (FifoLayer/FifoLayerConsumption audit trail).
 */
class StockLedgerExportService
{
    private const COMPANY_NAME = 'PT. KALINDO ETAM';

    /** @var string[] */
    private const SUMMARY_COLUMNS = [
        'DATE', 'REFERENCE DOCUMENT', 'VOUCHER TYPE', 'CUSTOMER', 'UOM',
        'QTY IN', 'QTY OUT', 'UNIT COST', 'AMOUNT', 'BALANCE QTY', 'BALANCE VALUE',
    ];

    /** @var string[] */
    private const DETAIL_COLUMNS = [
        'DATE', 'ITEM CODE', 'ITEM NAME', 'LOCATION', 'CUSTOMER', 'VOUCHER TYPE',
        'REFERENCE DOCUMENT', 'MOVEMENT TYPE', 'UOM', 'QTY IN', 'QTY OUT',
        'RUNNING BALANCE', 'UNIT COST', 'VALUE IN', 'VALUE OUT', 'BALANCE VALUE',
    ];

    public function __construct(
        protected StockLedgerRepository $stockLedgerRepository,
        protected StockLedgerService $stockLedgerService,
    ) {}

    /** @return array{0: string, 1: string} [from, to] as Y-m-d, resolved from the actual data when the filter is empty. */
    public function resolveDateRange(array $filters): array
    {
        if (($filters['date_from'] ?? null) && ($filters['date_to'] ?? null)) {
            return [$filters['date_from'], $filters['date_to']];
        }

        $range = $this->stockLedgerRepository->dateRange($filters);
        $today = now()->format('Y-m-d');

        return [
            $filters['date_from'] ?? ($range['from'] ? Carbon::parse($range['from'])->format('Y-m-d') : $today),
            $filters['date_to'] ?? ($range['to'] ? Carbon::parse($range['to'])->format('Y-m-d') : $today),
        ];
    }

    public function fileName(array $filters): string
    {
        [$from, $to] = $this->resolveDateRange($filters);

        return sprintf('Stock_Ledger_%s_%s.xlsx', Carbon::parse($from)->format('dmY'), Carbon::parse($to)->format('dmY'));
    }

    /** @return array{rows: array<int, array>, meta: array} Flat Detail sheet — every currently-active filter applied, matching the on-screen table 1:1. */
    public function detailRows(array $filters): array
    {
        $data = $this->annotate($this->stockLedgerRepository->searchAll($filters));

        $body = $data->map(function (StockLedger $row) {
            $qtyChange = (float) $row->qty_change;

            return [
                optional($row->posting_datetime)->format('d/m/Y'),
                $row->item?->item_code,
                $row->item?->item_name,
                $row->warehouse?->name,
                $row->customer_name,
                $this->formatLabel($row->voucher_type?->value),
                $row->reference_no,
                $this->formatLabel($row->transaction_type?->value),
                $row->item?->uom?->name,
                $qtyChange > 0 ? $qtyChange : null,
                $qtyChange < 0 ? abs($qtyChange) : null,
                (float) $row->balance_qty,
                $row->unit_cost ?: null,
                $row->value_in ?: null,
                $row->value_out ?: null,
                $row->balance_value ?? null,
            ];
        })->values()->all();

        $lastRow = 1 + count($body);
        $lastColumn = 'P';

        return [
            'rows' => [self::DETAIL_COLUMNS, ...$body],
            'meta' => [
                'lastColumn' => $lastColumn,
                'styleRanges' => [
                    ['range' => "A1:{$lastColumn}1", 'bold' => true, 'background' => 'D9D9D9'],
                ],
                'numberFormats' => $lastRow > 1 ? [
                    ['range' => "J2:J{$lastRow}", 'format' => '#,##0.00;[Red](#,##0.00)'],
                    ['range' => "K2:K{$lastRow}", 'format' => '#,##0.00;[Red](#,##0.00)'],
                    ['range' => "L2:P{$lastRow}", 'format' => '#,##0.00;[Red](#,##0.00)'],
                ] : [],
                'freezePane' => 'A2',
                'autoFilter' => "A1:{$lastColumn}1",
                'autoSize' => true,
            ],
        ];
    }

    /** @return array{rows: array<int, array>, meta: array} */
    public function summaryRows(array $filters): array
    {
        [$from, $to] = $this->resolveDateRange($filters);

        $openingBalances = $this->stockLedgerRepository->openingBalances($filters, $from);
        $transactions = $this->annotate($this->stockLedgerRepository->summaryTransactions($filters, $from, $to));
        $txByPair = $transactions->groupBy(fn (StockLedger $t) => "{$t->item_id}|{$t->warehouse_id}");

        $pairs = collect(array_keys($openingBalances))
            ->merge($txByPair->keys())
            ->unique()
            ->filter(fn (string $pair) => abs($openingBalances[$pair] ?? 0.0) > 0.00005 || $txByPair->has($pair))
            ->values();

        if ($pairs->isEmpty()) {
            return $this->assemble([], $filters, $from, $to);
        }

        $itemIds = $pairs->map(fn ($pair) => explode('|', $pair)[0])->unique()->values();
        $warehouseIds = $pairs->map(fn ($pair) => explode('|', $pair)[1])->unique()->values();

        $items = Item::query()->with('itemGroup', 'uom')->whereIn('id', $itemIds)->get()->keyBy('id');
        $warehouses = Warehouse::query()->whereIn('id', $warehouseIds)->get()->keyBy('id');
        $currentCosts = $this->stockLedgerService->currentAverageCostsFor($pairs);

        // Group pairs: warehouse_id -> item_group_id -> item_id
        $byLocation = $pairs->groupBy(fn ($pair) => explode('|', $pair)[1])
            ->map(fn ($locationPairs) => $locationPairs->groupBy(function ($pair) use ($items) {
                $itemId = explode('|', $pair)[0];

                return $items[$itemId]?->item_group_id;
            }));

        $locations = [];
        foreach ($byLocation as $warehouseId => $groupedByItemGroup) {
            $warehouse = $warehouses[$warehouseId] ?? null;
            $itemGroups = [];

            foreach ($groupedByItemGroup as $groupPairs) {
                $itemGroupModel = $items[explode('|', $groupPairs->first())[0]]?->itemGroup;
                $itemsOut = [];

                foreach ($groupPairs->sortBy(fn ($pair) => $items[explode('|', $pair)[0]]?->item_code) as $pair) {
                    [$itemId] = explode('|', $pair);
                    $item = $items[$itemId] ?? null;
                    $openingQty = (float) ($openingBalances[$pair] ?? 0.0);
                    $cost = $currentCosts[$pair] ?? 0.0;
                    $txns = $txByPair->get($pair, collect());

                    $runningQty = $openingQty;
                    $qtyInTotal = 0.0;
                    $qtyOutTotal = 0.0;
                    $txnRows = [];

                    foreach ($txns as $txn) {
                        /** @var StockLedger $txn */
                        $qtyChange = (float) $txn->qty_change;
                        $runningQty += $qtyChange;
                        if ($qtyChange > 0) {
                            $qtyInTotal += $qtyChange;
                        } else {
                            $qtyOutTotal += abs($qtyChange);
                        }

                        $txnRows[] = [
                            optional($txn->posting_datetime)->format('d/m/Y'),
                            $txn->reference_no,
                            $this->formatLabel($txn->voucher_type?->value),
                            $txn->customer_name,
                            $item?->uom?->name,
                            $qtyChange > 0 ? $qtyChange : null,
                            $qtyChange < 0 ? abs($qtyChange) : null,
                            $txn->unit_cost ?: null,
                            $qtyChange > 0 ? ($txn->value_in ?: null) : ($txn->value_out ? -$txn->value_out : null),
                            $runningQty,
                            round($runningQty * $cost, 2),
                        ];
                    }

                    $itemsOut[] = [
                        'code' => $item?->item_code,
                        'name' => $item?->item_name,
                        'openingQty' => $openingQty,
                        'openingValue' => round($openingQty * $cost, 2),
                        'txnRows' => $txnRows,
                        'qtyInTotal' => $qtyInTotal,
                        'qtyOutTotal' => $qtyOutTotal,
                        'closingQty' => $runningQty,
                        'closingValue' => round($runningQty * $cost, 2),
                    ];
                }

                $itemGroups[] = [
                    'name' => $itemGroupModel?->name ?? 'Ungrouped',
                    'items' => $itemsOut,
                    'qtyInTotal' => array_sum(array_column($itemsOut, 'qtyInTotal')),
                    'qtyOutTotal' => array_sum(array_column($itemsOut, 'qtyOutTotal')),
                    'closingQty' => array_sum(array_column($itemsOut, 'closingQty')),
                    'closingValue' => array_sum(array_column($itemsOut, 'closingValue')),
                ];
            }

            usort($itemGroups, fn ($a, $b) => strcmp($a['name'], $b['name']));

            $locations[] = [
                'name' => $warehouse?->name ?? 'Unknown',
                'itemGroups' => $itemGroups,
                'qtyInTotal' => array_sum(array_column($itemGroups, 'qtyInTotal')),
                'qtyOutTotal' => array_sum(array_column($itemGroups, 'qtyOutTotal')),
                'closingQty' => array_sum(array_column($itemGroups, 'closingQty')),
                'closingValue' => array_sum(array_column($itemGroups, 'closingValue')),
            ];
        }

        usort($locations, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $this->assemble($locations, $filters, $from, $to);
    }

    /** @param array<int, mixed> $rows already-annotated StockLedger models via a throwaway paginator, so attachCostInfo()/attachCustomerInfo() (LengthAwarePaginator-shaped) can be reused as-is. */
    private function annotate(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $paginator = new LengthAwarePaginator($rows, $rows->count(), max($rows->count(), 1));
        $this->stockLedgerService->attachCostInfo($paginator);
        $this->stockLedgerService->attachCustomerInfo($paginator);

        return $paginator->getCollection();
    }

    private function formatLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return implode(' ', array_map('ucfirst', explode('_', $value)));
    }

    /** @param array<int, mixed> $locations built by summaryRows() */
    private function assemble(array $locations, array $filters, string $from, string $to): array
    {
        $lastColumn = 'K';

        $locationLabel = ($filters['warehouse_id'] ?? null)
            ? (Warehouse::find($filters['warehouse_id'])?->name ?? 'All')
            : 'All';
        $itemLabel = 'All';
        if ($filters['item_id'] ?? null) {
            $item = Item::find($filters['item_id']);
            $itemLabel = $item ? "{$item->item_code} — {$item->item_name}" : 'All';
        }

        $rows = [
            [self::COMPANY_NAME],
            ['Stock Ledger Report'],
            [sprintf(
                'Period: %s - %s   |   Location: %s   |   Item: %s',
                Carbon::parse($from)->format('d/m/Y'),
                Carbon::parse($to)->format('d/m/Y'),
                $locationLabel,
                $itemLabel,
            )],
            ['Generated: ' . now()->format('d/m/Y H:i:s')],
            [''],
            self::SUMMARY_COLUMNS,
        ];

        $mergeRanges = ["A1:{$lastColumn}1", "A2:{$lastColumn}2", "A3:{$lastColumn}3", "A4:{$lastColumn}4"];
        $styleRanges = [
            ['range' => "A1:{$lastColumn}1", 'bold' => true, 'hAlign' => 'center', 'fontSize' => 14],
            ['range' => "A2:{$lastColumn}2", 'hAlign' => 'center'],
            ['range' => "A3:{$lastColumn}3", 'hAlign' => 'center'],
            ['range' => "A4:{$lastColumn}4", 'hAlign' => 'center'],
            ['range' => "A6:{$lastColumn}6", 'bold' => true, 'background' => 'D9D9D9'],
        ];

        $rowNum = 6;
        $grandQtyIn = 0.0;
        $grandQtyOut = 0.0;
        $grandClosingQty = 0.0;
        $grandClosingValue = 0.0;

        foreach ($locations as $location) {
            $rowNum++;
            $rows[] = ["Location: {$location['name']}", ...array_fill(0, 10, null)];
            $mergeRanges[] = "A{$rowNum}:{$lastColumn}{$rowNum}";
            $styleRanges[] = ['range' => "A{$rowNum}:{$lastColumn}{$rowNum}", 'bold' => true, 'background' => '404040', 'fontColor' => 'FFFFFF'];

            foreach ($location['itemGroups'] as $itemGroup) {
                $rowNum++;
                $rows[] = ['  Item Group: ' . $itemGroup['name'], ...array_fill(0, 10, null)];
                $styleRanges[] = ['range' => "A{$rowNum}:{$lastColumn}{$rowNum}", 'bold' => true, 'background' => 'D9D9D9'];

                foreach ($itemGroup['items'] as $item) {
                    $rowNum++;
                    $rows[] = ['    ' . $item['code'] . ' — ' . $item['name'], ...array_fill(0, 10, null)];
                    $styleRanges[] = ['range' => "A{$rowNum}", 'bold' => true, 'italic' => true];

                    $rowNum++;
                    $rows[] = [Carbon::parse($from)->format('d/m/Y'), 'B/F', null, null, null, null, null, null, null, $item['openingQty'], $item['openingValue']];

                    foreach ($item['txnRows'] as $txnRow) {
                        $rowNum++;
                        $rows[] = $txnRow;
                    }

                    $rowNum++;
                    $rows[] = [null, "Subtotal — {$item['name']}", null, null, null, $item['qtyInTotal'], $item['qtyOutTotal'], null, null, $item['closingQty'], $item['closingValue']];
                    $styleRanges[] = ['range' => "A{$rowNum}:{$lastColumn}{$rowNum}", 'bold' => true];
                }

                $rowNum++;
                $rows[] = [null, "Subtotal — Item Group: {$itemGroup['name']}", null, null, null, $itemGroup['qtyInTotal'], $itemGroup['qtyOutTotal'], null, null, $itemGroup['closingQty'], $itemGroup['closingValue']];
                $styleRanges[] = ['range' => "A{$rowNum}:{$lastColumn}{$rowNum}", 'bold' => true, 'background' => 'D9D9D9'];
            }

            $rowNum++;
            $rows[] = [null, "Total — Location: {$location['name']}", null, null, null, $location['qtyInTotal'], $location['qtyOutTotal'], null, null, $location['closingQty'], $location['closingValue']];
            $styleRanges[] = ['range' => "A{$rowNum}:{$lastColumn}{$rowNum}", 'bold' => true, 'background' => '404040', 'fontColor' => 'FFFFFF'];

            $grandQtyIn += $location['qtyInTotal'];
            $grandQtyOut += $location['qtyOutTotal'];
            $grandClosingQty += $location['closingQty'];
            $grandClosingValue += $location['closingValue'];
        }

        $rowNum++;
        $rows[] = ['GRAND TOTAL', null, null, null, null, $grandQtyIn, $grandQtyOut, null, null, $grandClosingQty, $grandClosingValue];
        $styleRanges[] = ['range' => "A{$rowNum}:{$lastColumn}{$rowNum}", 'bold' => true, 'borderTop' => true, 'borderTopThick' => true];

        return [
            'rows' => $rows,
            'meta' => [
                'lastColumn' => $lastColumn,
                'mergeRanges' => $mergeRanges,
                'styleRanges' => $styleRanges,
                'numberFormats' => [
                    ['range' => "F7:K{$rowNum}", 'format' => '#,##0.00;[Red](#,##0.00)'],
                ],
                'freezePane' => 'A7',
                'autoSize' => true,
            ],
        ];
    }
}
