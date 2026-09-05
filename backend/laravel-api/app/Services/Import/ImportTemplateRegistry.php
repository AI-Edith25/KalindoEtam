<?php

namespace App\Services\Import;

use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\Templates\CustomerImportTemplate;
use App\Services\Import\Templates\ItemGroupImportTemplate;
use App\Services\Import\Templates\ItemImportTemplate;
use App\Services\Import\Templates\ItemStandardRateImportTemplate;
use App\Services\Import\Templates\SupplierImportTemplate;
use App\Services\Import\Templates\TermsOfPaymentImportTemplate;
use App\Services\Import\Templates\UomImportTemplate;
use App\Services\Import\Templates\WarehouseImportTemplate;
use InvalidArgumentException;

/** Plain array map — keyed by the URL module slug used throughout the Import Wizard routes. */
final class ImportTemplateRegistry
{
    /** @var array<string, class-string<ImportTemplate>> */
    private const TEMPLATES = [
        'items' => ItemImportTemplate::class,
        'item-groups' => ItemGroupImportTemplate::class,
        'uoms' => UomImportTemplate::class,
        'terms-of-payments' => TermsOfPaymentImportTemplate::class,
        'warehouses' => WarehouseImportTemplate::class,
        'suppliers' => SupplierImportTemplate::class,
        'customers' => CustomerImportTemplate::class,
        'item-standard-rates' => ItemStandardRateImportTemplate::class,
    ];

    public function resolve(string $key): ImportTemplate
    {
        $class = self::TEMPLATES[$key] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Unknown import module \"{$key}\".");
        }

        return new $class;
    }
}
