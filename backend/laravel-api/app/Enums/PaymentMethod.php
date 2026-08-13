<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH = 'cash';
    case BANK_TRANSFER = 'bank_transfer';
    case CHEQUE = 'cheque';
    case GIRO = 'giro';
    case QRIS = 'qris';
    case CREDIT_CARD = 'credit_card';
}
