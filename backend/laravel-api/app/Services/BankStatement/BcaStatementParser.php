<?php

namespace App\Services\BankStatement;

use Carbon\Carbon;

/**
 * Semicolon-delimited, single header row:
 * AccountNo;Ccy;PostDate;Remarks;AdditionalDesc;Credit Amount;Debit Amount;Close Balance
 * PostDate is "01 September 2026 14:24:27" -- Indonesian or English month name, so
 * Carbon::parse/strtotime can't be trusted; looked up manually below.
 */
class BcaStatementParser implements BankStatementParser
{
    private const MONTHS = [
        'januari' => 1, 'january' => 1,
        'februari' => 2, 'february' => 2,
        'maret' => 3, 'march' => 3,
        'april' => 4,
        'mei' => 5, 'may' => 5,
        'juni' => 6, 'june' => 6,
        'juli' => 7, 'july' => 7,
        'agustus' => 8, 'august' => 8,
        'september' => 9,
        'oktober' => 10, 'october' => 10,
        'november' => 11,
        'desember' => 12, 'december' => 12,
    ];

    public function code(): string
    {
        return 'bca';
    }

    public function detect(string $rawContent): bool
    {
        $firstLine = strtok($rawContent, "\r\n");

        return $firstLine !== false && str_starts_with(trim($firstLine), 'AccountNo;');
    }

    public function parse(string $rawContent): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($rawContent));
        $rows = [];

        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $columns = explode(';', $line);
            [$accountNo, $ccy, $postDate, $remarks, $additionalDesc, $creditAmount, $debitAmount, $closeBalance] = array_pad($columns, 8, '');

            $rows[] = [
                'transaction_date' => $this->parseDate(trim($postDate)),
                'description' => trim($remarks),
                'debit_amount' => (float) $debitAmount,
                'credit_amount' => (float) $creditAmount,
                'running_balance' => $closeBalance === '' ? null : (float) $closeBalance,
            ];
        }

        return $rows;
    }

    private function parseDate(string $value): Carbon
    {
        // "01 September 2026 14:24:27"
        [$day, $monthName, $year, $time] = array_pad(explode(' ', $value, 4), 4, '00:00:00');
        $month = self::MONTHS[strtolower($monthName)] ?? throw new \InvalidArgumentException("Unrecognized month name: {$monthName}");

        return Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', (int) $year, $month, (int) $day, $time));
    }
}
