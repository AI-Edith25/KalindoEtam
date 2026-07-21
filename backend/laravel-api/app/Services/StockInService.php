<?php

namespace App\Services;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Models\StockIn;
use App\Repositories\StockInRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockInService
{
    public function __construct(
        protected StockInRepository $stockInRepository,
        protected StockLedgerService $stockLedgerService,
    ) {}

    /**
     * @return Collection<int, StockIn>
     */
    public function store(array $data): Collection
    {
        return DB::transaction(function () use ($data) {
            return collect($data['items'])->map(function (array $line) use ($data) {
                $stockIn = $this->stockInRepository->create([
                    'item_id' => $line['item_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'qty_in' => $line['qty'],
                    'date_in' => $data['date'],
                ]);

                $this->stockLedgerService->record(
                    itemId: $stockIn->item_id,
                    warehouseId: $stockIn->warehouse_id,
                    transactionType: StockTransactionType::IN,
                    voucherType: StockVoucherType::STOCK_IN,
                    voucherId: $stockIn->id,
                    qtyChange: $stockIn->qty_in,
                    postingDatetime: now(),
                    remarks: $data['remarks'] ?? null,
                );

                return $stockIn;
            });
        });
    }
}
