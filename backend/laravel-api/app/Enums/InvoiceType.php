<?php

namespace App\Enums;

/**
 * Drives which Naming Series generates the Invoice's document_number —
 * see Invoice::documentType() and docs behind Sprint 2 (Invoice Numbering).
 */
enum InvoiceType: string
{
    case GOODS = 'goods';
    case TRANSPORTATION = 'transportation';
}
