<?php

namespace App\Services\BankStatement;

interface BankStatementParser
{
    /** Stable key stored on bank_statements.format_template, e.g. 'bca'. */
    public function code(): string;

    /** Cheap structural check on the raw file content, for auto-detect. */
    public function detect(string $rawContent): bool;

    /**
     * @return array<int, array{transaction_date: \Carbon\Carbon, description: string, debit_amount: float, credit_amount: float, running_balance: ?float}>
     */
    public function parse(string $rawContent): array;
}
