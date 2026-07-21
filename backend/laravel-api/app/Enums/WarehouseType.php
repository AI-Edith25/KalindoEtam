<?php

namespace App\Enums;

enum WarehouseType: string
{
    case MAIN = 'main';
    case TRANSIT = 'transit';
    case RETURN = 'return';
}
