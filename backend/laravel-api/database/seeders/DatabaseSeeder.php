<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(MasterDataSeeder::class);
        $this->call(ChartOfAccountsSeeder::class);
        $this->call(ReportAccountMappingSeeder::class);
        $this->call(BalanceSheetMappingSeeder::class);
        $this->call(CashFlowMappingSeeder::class);
        $this->call(DocumentEngineSeeder::class);
    }
}
