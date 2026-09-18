<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Row;

/**
 * Generic row-by-row streaming glue for a large .csv/.xlsx/.xls file — not
 * journal-specific, reusable by any future large-file import. Exists because
 * ImportFileReader::readRaw() materializes the entire sheet into one PHP
 * array (fine for the hundreds-of-rows files it was built for; its own
 * `ponytail:` comment already flags this exact upgrade path), which risks
 * memory exhaustion on a real ~170k-row/7MB+ export. $onRow is called once
 * per row with a plain 0-indexed array (same shape ImportFileReader::readRaw()
 * rows use) plus the row's 1-indexed position, so callers can group/flush as
 * they go instead of holding the whole file in memory.
 *
 * Forces comma as the CSV delimiter (WithCustomCsvSettings) rather than
 * trusting PhpSpreadsheet's own auto-detection — confirmed by hand that it
 * mis-guesses space-delimited for a file like this (title rows with no
 * commas at all skew its sample), where ImportFileReader::detectDelimiter()
 * gets it right. Every CSV export this app produces (JournalListExport
 * included) is comma-delimited, so this matches reality rather than
 * special-casing a delimiter nothing here actually uses.
 */
final class StreamedRowImport implements OnEachRow, WithChunkReading, WithCustomCsvSettings
{
    public function __construct(private readonly \Closure $onRow, private readonly int $chunkSize = 2000) {}

    public function onRow(Row $row): void
    {
        ($this->onRow)($row->toArray(), $row->getIndex());
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ','];
    }
}
