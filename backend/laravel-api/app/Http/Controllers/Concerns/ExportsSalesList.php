<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\BusinessException;
use App\Exports\SalesListExport;
use Closure;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Shared by every Sales module's export() action (SalesOrder, Delivery,
 * Invoice, CreditNote, DebitNote) — column selection, the
 * `{modul}_{tanggal-awal}_{tanggal-akhir}.xlsx` filename convention, and the
 * empty-result guard, all in one place instead of 5 near-identical copies.
 */
trait ExportsSalesList
{
    /**
     * @param  array<string, string>  $catalog  Ordered [columnKey => label] — every exportable column, in display order.
     * @param  array<int, string>|null  $requestedKeys  Subset of $catalog's keys the caller asked for; null/empty = all columns.
     * @param  Closure(mixed, string): mixed  $resolve  (row, columnKey) => cell value.
     */
    protected function exportSalesList(
        Collection $rows,
        array $catalog,
        ?array $requestedKeys,
        Closure $resolve,
        string $modulePrefix,
        ?string $dateFrom,
        ?string $dateTo,
        string $format,
    ): BinaryFileResponse {
        if ($rows->isEmpty()) {
            throw new BusinessException('Tidak ada data untuk diekspor.');
        }

        $keys = empty($requestedKeys) ? array_keys($catalog) : array_values(array_intersect(array_keys($catalog), $requestedKeys));
        $columns = array_map(fn ($key) => ['key' => $key, 'label' => $catalog[$key]], $keys);

        $filename = sprintf(
            '%s_%s_%s.%s',
            $modulePrefix,
            $dateFrom ?? now()->toDateString(),
            $dateTo ?? now()->toDateString(),
            $format,
        );

        return Excel::download(new SalesListExport($rows, $columns, $resolve), $filename);
    }
}
