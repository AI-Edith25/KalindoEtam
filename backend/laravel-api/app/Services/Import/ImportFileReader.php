<?php

namespace App\Services\Import;

use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Reads an uploaded CSV/XLSX/XLS file into headers + associative rows, given
 * an explicit header row and data-start row (1-indexed, matching how a
 * spreadsheet-literate user thinks about row numbers) — legacy exports
 * routinely have preamble rows (title, company name, marker rows) above the
 * real header and sometimes a type-spec row right after it, so this can
 * never be assumed to be row 1. HeaderDetector picks the defaults; the user
 * can override them.
 *
 * .csv goes through raw fopen/fgetcsv (true one-row-at-a-time, stdlib) —
 * Maatwebsite's chunk reader re-scans from the start of the file per chunk
 * for CSV, so it buys nothing there. .xlsx/.xls go through Maatwebsite
 * (already installed) since master-data files are hundreds-to-low-thousands
 * of rows, not the scale that would need its chunked reader.
 * // ponytail: upgrade the xlsx path to WithChunkReading if someone ever
 * imports a huge spreadsheet.
 */
final class ImportFileReader
{
    /** @return array{headers: string[], rows: array<int, array<string, mixed>>} */
    public static function read(string $path, string $extension, int $headerRow = 1, int $dataStartRow = 2): array
    {
        $raw = self::readRaw($path, $extension);

        if ($raw === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headerLine = $raw[$headerRow - 1] ?? [];
        $headers = array_map(fn ($h) => trim((string) $h), $headerLine);
        $columnCount = count($headers);

        $dataLines = array_slice($raw, $dataStartRow - 1);

        return [
            'headers' => $headers,
            'rows' => array_map(
                fn (array $row) => array_combine($headers, array_pad(array_slice($row, 0, $columnCount), $columnCount, null)),
                $dataLines
            ),
        ];
    }

    /**
     * Every row as a plain 0-indexed array, no header assumption — the base
     * both read() and HeaderDetector build on.
     *
     * @return array<int, array<int, mixed>>
     */
    public static function readRaw(string $path, string $extension): array
    {
        return strtolower($extension) === 'csv'
            ? self::readRawCsv($path)
            : self::readRawSpreadsheet($path);
    }

    private static function readRawCsv(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException('Unable to read uploaded file.');
        }

        // Strip a UTF-8 BOM; convert legacy Latin-1 files to UTF-8 (both encodings in scope).
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        }

        $delimiter = self::detectDelimiter(strtok($raw, "\r\n") ?: '');

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($line === [null]) {
                continue;
            }

            $rows[] = $line;
        }

        fclose($handle);

        return $rows;
    }

    private static function detectDelimiter(string $sampleLine): string
    {
        $candidates = [',', ';', "\t"];
        $counts = array_map(fn ($d) => substr_count($sampleLine, $d), $candidates);
        $best = array_search(max($counts), $counts, true);

        return $best !== false && $counts[$best] > 0 ? $candidates[$best] : ',';
    }

    private static function readRawSpreadsheet(string $path): array
    {
        $sheet = Excel::toCollection(null, $path)->first();

        return $sheet?->toArray() ?? [];
    }
}
