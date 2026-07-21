<?php

namespace App\Enums;

/** See docs/TAX_ENGINE_DESIGN.md §4. */
enum TaxCalculationMode: string
{
    case EXCLUSIVE = 'exclusive';
    case INCLUSIVE = 'inclusive';
}
