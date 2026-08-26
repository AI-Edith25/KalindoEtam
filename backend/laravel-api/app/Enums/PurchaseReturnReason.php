<?php

namespace App\Enums;

/**
 * Classification only — PurchaseReturnService never branches on this value.
 * The mechanism is the same for every reason (return lines + optional
 * header tax adjustment); this only drives the frontend's defaults. Mirrors
 * CreditNoteReason's docblock/shape.
 */
enum PurchaseReturnReason: string
{
    case DAMAGED_GOODS = 'damaged_goods';
    case WRONG_ITEM = 'wrong_item';
    case QUANTITY_DISCREPANCY = 'quantity_discrepancy';
    case PRICE_CORRECTION = 'price_correction';
    case LATE_DELIVERY = 'late_delivery';
}
