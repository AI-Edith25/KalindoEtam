<?php

namespace App\Enums;

enum BankReconciliationStatus: string
{
    case BALANCED = 'balanced';
    case UNBALANCED = 'unbalanced';
    case NOT_UPLOADED = 'not_uploaded';
}
