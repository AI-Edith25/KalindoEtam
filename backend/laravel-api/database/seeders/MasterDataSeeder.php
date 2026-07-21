<?php

namespace Database\Seeders;

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

        Tax::query()->firstOrCreate(
            ['name' => 'PPN 11%'],
            ['rate' => 11.00, 'is_active' => true]
        );
    }
}
