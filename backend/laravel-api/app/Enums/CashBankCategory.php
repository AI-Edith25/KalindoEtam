<?php

namespace App\Enums;

enum CashBankCategory: string
{
    case PETTY_CASH = 'petty_cash';
    case CASH_BOOK = 'cash_book';

    public function label(): string
    {
        return match ($this) {
            self::PETTY_CASH => 'Petty Cash',
            self::CASH_BOOK => 'Cash Book',
        };
    }
}
