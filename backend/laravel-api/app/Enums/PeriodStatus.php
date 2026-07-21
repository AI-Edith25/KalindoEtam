<?php

namespace App\Enums;

/**
 * An Accounting Period's status — exactly two states. A period is not an
 * authored document (Documentable's DRAFT/SUBMITTED/CANCELLED has no
 * meaning here); it is a boolean gate over a date range, either accepting
 * postings or not. See docs/PERIOD_CLOSING_DESIGN.md §1.
 */
enum PeriodStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
}
