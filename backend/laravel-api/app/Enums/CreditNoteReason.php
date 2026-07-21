<?php

namespace App\Enums;

/**
 * Classification only — CreditNoteService never branches on this value.
 * The mechanism is the same for every reason (credit lines + optional
 * header discount/tax adjustment); this only drives the frontend's
 * defaults. See docs/CREDIT_NOTE_DESIGN.md §1.
 */
enum CreditNoteReason: string
{
    case FULL_CREDIT = 'full_credit';
    case PARTIAL_CREDIT = 'partial_credit';
    case PRICE_ADJUSTMENT = 'price_adjustment';
    case RETURNED_GOODS = 'returned_goods';
    case SERVICE_REFUND = 'service_refund';
    case TAX_ADJUSTMENT = 'tax_adjustment';
}
