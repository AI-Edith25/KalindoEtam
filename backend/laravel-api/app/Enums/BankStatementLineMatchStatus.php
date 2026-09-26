<?php

namespace App\Enums;

enum BankStatementLineMatchStatus: string
{
    case UNMATCHED = 'unmatched';
    case MATCHED = 'matched';
    case MANUAL_MATCHED = 'manual_matched';
}
