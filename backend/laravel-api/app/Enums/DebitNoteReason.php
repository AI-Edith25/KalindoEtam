<?php

namespace App\Enums;

/**
 * Classification only — DebitNoteService never branches on this value.
 * Under-Billed Invoice / Price Correction are item-linked lines; Additional
 * Service Charge / Freight Adjustment are freestanding lines; Tax
 * Adjustment is header-only. The mechanism is decided by line shape
 * (invoice_item_id set or not), never by reason. See docs/DEBIT_NOTE_DESIGN.md §1.
 */
enum DebitNoteReason: string
{
    case UNDER_BILLED_INVOICE = 'under_billed_invoice';
    case PRICE_CORRECTION = 'price_correction';
    case ADDITIONAL_SERVICE_CHARGE = 'additional_service_charge';
    case FREIGHT_ADJUSTMENT = 'freight_adjustment';
    case TAX_ADJUSTMENT = 'tax_adjustment';
}
