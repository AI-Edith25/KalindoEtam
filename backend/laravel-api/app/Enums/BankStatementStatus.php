<?php

namespace App\Enums;

enum BankStatementStatus: string
{
    case UPLOADED = 'uploaded';
    case PROCESSED = 'processed';
    case ERROR = 'error';
}
