<?php

namespace App\Repositories;

use App\Models\PurchaseInvoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class PurchaseInvoiceRepository extends BaseRepository
{
    protected const EAGER = ['supplier', 'purchaseOrder', 'purchaseOrders', 'goodsReceipt.warehouse', 'goodsReceipts', 'items', 'creator', 'updater', 'accountsPayable'];

    public function __construct(PurchaseInvoice $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('invoice_date')->paginate($perPage);
    }

    /** Same filtering shape as InvoiceRepository::search(). */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['goods_receipt_id'] ?? null, fn ($query, $goodsReceiptId) => $query->where('goods_receipt_id', $goodsReceiptId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('supplier_name', 'like', "%{$search}%"))
            ))
            ->latest('invoice_date')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    /**
     * Unpaginated, for export. When $ids is given, it replaces the whole filter chain rather
     * than AND-ing with it — checked rows on the list means "export exactly these". Mirrors
     * InvoiceRepository::searchAll().
     */
    public function searchAll(array $filters, ?array $ids = null): Collection
    {
        if (! empty($ids)) {
            return $this->model->query()->with(self::EAGER)->whereIn('id', $ids)->latest('invoice_date')->get();
        }

        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('supplier_name', 'like', "%{$search}%"))
            ))
            ->latest('invoice_date')
            ->get();
    }
}
