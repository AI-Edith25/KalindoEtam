<?php

namespace App\Enums;

/** Drives whether Invoice::discount_amount is a fixed Rupiah figure or derived from discount_percentage — see InvoiceService::resolveDiscount(). */
enum DiscountType: string
{
    case AMOUNT = 'amount';
    case PERCENTAGE = 'percentage';
}
