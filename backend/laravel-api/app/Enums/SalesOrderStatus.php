<?php

namespace App\Enums;

enum SalesOrderStatus: string
{
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case CANCELLED = 'cancelled';
}
