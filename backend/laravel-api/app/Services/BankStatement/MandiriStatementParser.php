<?php

namespace App\Services\BankStatement;

use Carbon\Carbon;

/**
 * Quoted-CSV with metadata rows before the real header ("No. rekening : ...",
 * "Nama : ...", "Periode : ...", "Kode Mata Uang : ..."), possibly separated by
 * blank lines, then:
 * "Tanggal Transaksi","Keterangan","Cabang","Jumlah","Saldo"
 * "Jumlah" is "32,000.00 DB" / "1,500,000.00 CR" (thousands-comma + debit/credit
 * suffix in one string). A footer summary block follows the data rows -- detected
 * because its first column isn't a DD/MM/YYYY date, and skipped rather than
 * treated as a parse error.
 */
class MandiriStatementParser implements BankStatementParser
{
    private const HEADER = ['Tanggal Transaksi', 'Keterangan', 'Cabang', 'Jumlah', 'Saldo'];

    public function code(): string
    {
        return 'mandiri';
    }

    public function detect(string $rawContent): bool
    {
        foreach (preg_split('/\r\n|\r|\n/', $rawContent) as $line) {
            if (str_getcsv($line) === self::HEADER) {
                return true;
            }
        }

        return false;
    }

    public function parse(string $rawContent): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $rawContent);
        $headerIndex = null;

        foreach ($lines as $index => $line) {
            if (str_getcsv($line) === self::HEADER) {
                $headerIndex = $index;
                break;
            }
        }

        if ($headerIndex === null) {
            throw new \InvalidArgumentException('Mandiri statement header row not found.');
        }

        $rows = [];
        foreach (array_slice($lines, $headerIndex + 1) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line);
            $date = $columns[0] ?? '';

            if (! preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
                // Footer/summary line (Saldo Awal, Mutasi Debet/Kredit, Saldo Akhir, ...) -- skip, not an error.
                continue;
            }

            [$amount, $direction] = $this->parseAmount($columns[3] ?? '');

            $rows[] = [
                'transaction_date' => Carbon::createFromFormat('d/m/Y', $date)->startOfDay(),
                'description' => trim($columns[1] ?? ''),
                'debit_amount' => $direction === 'DB' ? $amount : 0.0,
                'credit_amount' => $direction === 'CR' ? $amount : 0.0,
                'running_balance' => $this->parseNumber($columns[4] ?? ''),
            ];
        }

        return $rows;
    }

    /** @return array{0: float, 1: string} */
    private function parseAmount(string $value): array
    {
        if (! preg_match('/^([\d,]+\.\d{2})\s*(DB|CR)$/', trim($value), $m)) {
            throw new \InvalidArgumentException("Unrecognized Jumlah value: {$value}");
        }

        return [(float) str_replace(',', '', $m[1]), $m[2]];
    }

    private function parseNumber(string $value): ?float
    {
        $value = trim($value);

        return $value === '' ? null : (float) str_replace(',', '', $value);
    }
}
