<?php

namespace Database\Seeders;

use App\Enums\TaxCalculationMode;
use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Models\Currency;
use App\Models\ItemGroup;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    /**
     * Seed baseline reference data so Master Data + Item are usable
     * immediately after install, without inventing business decisions
     * (Customer/Supplier are left empty — real parties, not defaults).
     */
    public function run(): void
    {
        collect(['Pcs', 'Kg', 'Sak', 'Meter', 'Batang'])->each(
            fn (string $name) => UnitOfMeasurement::query()->firstOrCreate(['name' => $name])
        );

        collect(['Semen', 'Besi', 'Cat', 'Pipa', 'Kayu'])->each(
            fn (string $name) => ItemGroup::query()->firstOrCreate(['name' => $name])
        );

        Currency::query()->firstOrCreate(
            ['code' => 'IDR'],
            ['name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'exchange_rate' => 1]
        );

        // A Tax record applies to exactly one side of a transaction — PPN 11% is seeded as a
        // Purchase-tagged and a Sales-tagged pair, same convention as the backfill migration
        // that duplicates any pre-existing Tax row.
        Tax::query()->firstOrCreate(
            ['code' => 'PPN11-P'],
            ['name' => 'PPN 11%', 'type' => TaxType::VAT, 'transaction_type' => TaxTransactionType::PURCHASE, 'rate' => 11.00, 'calculation_mode' => TaxCalculationMode::EXCLUSIVE, 'is_active' => true]
        );
        Tax::query()->firstOrCreate(
            ['code' => 'PPN11-S'],
            ['name' => 'PPN 11%', 'type' => TaxType::VAT, 'transaction_type' => TaxTransactionType::SALES, 'rate' => 11.00, 'calculation_mode' => TaxCalculationMode::EXCLUSIVE, 'is_active' => true]
        );
    }
}
