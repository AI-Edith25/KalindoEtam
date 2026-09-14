<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Report metadata (company, title, generated-at/by, active filters) that the
 * legacy xlsDeliveryOrderListing_Detail.xlsx template used to merge into the
 * data sheet's own header rows. Lives on its own sheet instead so the data
 * sheet stays a single header row with nothing but the 25 data columns —
 * see DeliveryDetailDataSheet. Small enough (a handful of rows) that a plain
 * FromArray with no chunking is fine.
 */
class DeliveryDetailInfoSheet implements FromArray, WithTitle
{
    /** @param array{company: string, title: string, generated_at: string, generated_by: string, filters: array<int, array{0: string, 1: string}>} $meta */
    public function __construct(protected array $meta) {}

    public function title(): string
    {
        return 'Info';
    }

    public function array(): array
    {
        $rows = [
            [$this->meta['company']],
            [$this->meta['title']],
            [''],
            ['Digenerate', $this->meta['generated_at']],
            ['Oleh', $this->meta['generated_by']],
            [''],
            ['Filter Aktif'],
        ];

        if (empty($this->meta['filters'])) {
            $rows[] = ['(tidak ada filter — seluruh data)'];
        } else {
            foreach ($this->meta['filters'] as $filter) {
                $rows[] = $filter;
            }
        }

        return $rows;
    }
}
