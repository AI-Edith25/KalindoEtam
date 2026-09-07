<?php

namespace App\Services;

use App\Repositories\TaxReportRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Thin passthrough to TaxReportRepository — same shape as AccountsPayableService. */
class TaxReportService
{
    public function __construct(protected TaxReportRepository $taxReportRepository) {}

    public function outputTax(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->taxReportRepository->paginateOutputTax($filters, $perPage);
    }

    public function inputTax(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->taxReportRepository->paginateInputTax($filters, $perPage);
    }

    public function outputTaxAll(array $filters): Collection
    {
        return $this->taxReportRepository->allOutputTax($filters);
    }

    public function inputTaxAll(array $filters): Collection
    {
        return $this->taxReportRepository->allInputTax($filters);
    }

    /**
     * Selisih = Total PPN Keluaran − Total PPN Masukan. Positive = "Kurang
     * Bayar" (owed to the state), negative = "Lebih Bayar" (overpaid) — the
     * label itself is a display concern, resolved by the frontend from the
     * sign of this one number, not stored or computed twice.
     */
    public function summary(array $outputFilters, array $inputFilters): array
    {
        $output = $this->taxReportRepository->outputTaxTotals($outputFilters);
        $input = $this->taxReportRepository->inputTaxTotals($inputFilters);

        return [
            'output_dpp' => $output['dpp'],
            'output_ppn' => $output['ppn'],
            'input_dpp' => $input['dpp'],
            'input_ppn' => $input['ppn'],
            'selisih' => $output['ppn'] - $input['ppn'],
        ];
    }
}
