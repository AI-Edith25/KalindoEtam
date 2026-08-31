<?php

namespace App\Services\Import;

use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Reads an uploaded CSV/XLSX/XLS file into headers + associative rows.
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
    /** @return array{headers: string[], rows: array<int, array<string, string|null>>} */
    public static function read(string $path, string $extension): array
    {
        return strtolower($extension) === 'csv'
            ? self::readCsv($path)
            : self::readSpreadsheet($path);
    }

    private static function readCsv(string $path): array
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

        $headers = fgetcsv($handle, 0, $delimiter);

        if ($headers === false) {
            fclose($handle);

            return ['headers' => [], 'rows' => []];
        }

        $headers = array_map(fn ($h) => trim((string) $h), $headers);
        $columnCount = count($headers);
        $rows = [];

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($line === [null]) {
                continue;
            }

            $rows[] = array_combine($headers, array_pad(array_slice($line, 0, $columnCount), $columnCount, null));
        }

        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function detectDelimiter(string $sampleLine): string
    {
        $candidates = [',', ';', "\t"];
        $counts = array_map(fn ($d) => substr_count($sampleLine, $d), $candidates);
        $best = array_search(max($counts), $counts, true);

        return $best !== false && $counts[$best] > 0 ? $candidates[$best] : ',';
    }

    private static function readSpreadsheet(string $path): array
    {
        $sheet = Excel::toCollection(null, $path)->first();
        $rows = $sheet?->toArray() ?? [];

        if ($rows === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_map(fn ($h) => trim((string) $h), $rows[0]);
        $columnCount = count($headers);
        $dataRows = array_slice($rows, 1);

        return [
            'headers' => $headers,
            'rows' => array_map(
                fn (array $row) => array_combine($headers, array_pad(array_slice($row, 0, $columnCount), $columnCount, null)),
                $dataRows
            ),
        ];
    }
}
