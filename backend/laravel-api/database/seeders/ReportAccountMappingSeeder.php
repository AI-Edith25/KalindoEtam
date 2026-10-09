<?php

namespace Database\Seeders;

use App\Enums\ProfitLossSection;
use App\Enums\ReportStatementType;
use App\Models\ChartOfAccount;
use App\Models\ReportAccountMapping;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Seeds Profit & Loss's account classification (docs/PROFIT_LOSS_DESIGN.md
 * §4) — one declarative list here, not scattered literals in application
 * code. Looks accounts up by code without failing the whole run if one is
 * missing (a future Chart of Accounts edit shouldn't break this seeder);
 * a missing code is logged instead, the same visibility principle
 * ProfitLossService applies to unmapped accounts at report time.
 */
class ReportAccountMappingSeeder extends Seeder
{
    /** @var array<string, ProfitLossSection> */
    protected const PROFIT_LOSS_MAPPINGS = [
        '4000' => ProfitLossSection::REVENUE,             // Sales Revenue
        '4050' => ProfitLossSection::REVENUE,              // Sales Returns and Allowances (contra)
        '4100' => ProfitLossSection::OTHER_INCOME,         // Other Income
        '4900' => ProfitLossSection::REVENUE,              // Discount Given (contra)
        '5000' => ProfitLossSection::COST_OF_GOODS_SOLD,   // Cost of Goods Sold
        '5100' => ProfitLossSection::COST_OF_GOODS_SOLD,   // Purchase Expense
        '6000' => ProfitLossSection::OPERATING_EXPENSE,    // Operating Expenses
        '6100' => ProfitLossSection::OPERATING_EXPENSE,    // Beban Transport
        '6200' => ProfitLossSection::OPERATING_EXPENSE,    // Beban Konsumsi
        '6300' => ProfitLossSection::OPERATING_EXPENSE,    // Beban ATK
        '6400' => ProfitLossSection::OPERATING_EXPENSE,    // Beban Listrik & Air
        '6900' => ProfitLossSection::OPERATING_EXPENSE,    // Beban Lain-lain

        // Below: the legacy SkyBiz chart (175 accounts, imported after the baseline
        // above) — every code never added here, so every one of these silently
        // never appeared on Income Statement. Sectioned the same way the legacy
        // system's own "Financial Category" tree already grouped them (I10 Income,
        // I15 Cost of Sales, I20 Other Income, I27 Operating Expenses, I29/I22/I30
        // Other Expenses), not re-derived from scratch.
        '411.01.01' => ProfitLossSection::REVENUE,         // JASA ANGKUTAN
        '4200' => ProfitLossSection::REVENUE,              // JASA ANGKUTAN

        '4101' => ProfitLossSection::OTHER_INCOME,         // PENDAPATAN LAIN-LAIN
        '4300' => ProfitLossSection::OTHER_INCOME,         // JASA GIRO & BUNGA BANK
        '710.01.01' => ProfitLossSection::OTHER_INCOME,    // JASA GIRO & BUNGA BANK
        '710.02.09' => ProfitLossSection::OTHER_INCOME,    // PENDAPATAN LAIN-LAIN

        '5010' => ProfitLossSection::COST_OF_GOODS_SOLD,       // SALDO AWAL
        '5020' => ProfitLossSection::COST_OF_GOODS_SOLD,       // SALDO AKHIR
        '510.01.01' => ProfitLossSection::COST_OF_GOODS_SOLD,  // SALDO AWAL
        '510.01.03' => ProfitLossSection::COST_OF_GOODS_SOLD,  // RETUR PEMBELIAN
        '510.01.04' => ProfitLossSection::COST_OF_GOODS_SOLD,  // DISKON PEMBELIAN
        '510.01.05' => ProfitLossSection::COST_OF_GOODS_SOLD,  // BIAYA PENGIRIMAN/EKSPEDISI (HPP)
        '510.01.06' => ProfitLossSection::COST_OF_GOODS_SOLD,  // BIAYA ASURANSI PENGIRIMAN (HPP)
        '510.01.09' => ProfitLossSection::COST_OF_GOODS_SOLD,  // SALDO AKHIR
        '5200' => ProfitLossSection::COST_OF_GOODS_SOLD,       // RETUR PEMBELIAN
        '5300' => ProfitLossSection::COST_OF_GOODS_SOLD,       // DISKON PEMBELIAN
        '5400' => ProfitLossSection::COST_OF_GOODS_SOLD,       // BIAYA PENGIRIMAN/EKSPEDISI (HPP)
        '5500' => ProfitLossSection::COST_OF_GOODS_SOLD,       // BIAYA ASURANSI PENGIRIMAN (HPP)

        // Biaya Operasional, Adm dan Umum (legacy 610.xx) — payroll, office,
        // marketing, maintenance, depreciation, shipping sub-accounts, plus
        // their already-renumbered 6xxx twins.
        '6010' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA GAJI
        '6020' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA TUNJANGAN DAN THR
        '6030' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA PESANGON
        '6040' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA BPJS DAN ASTEK
        '6050' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA UPAH HARIAN
        '6060' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA LEMBUR, KOMISI DAN INSENTIF
        '6070' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA PULSA HP
        '6080' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA KEAMANAN
        '610.01.01' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA GAJI
        '610.01.03' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA LEMBUR, KOMISI DAN INSENTIF
        '610.01.04' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA TUNJANGAN DAN THR
        '610.01.06' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA BPJS DAN ASTEK
        '610.01.09' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA UPAH HARIAN
        '610.01.10' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA PESANGON
        '610.01.12' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA KEAMANAN
        '610.02.02' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA KANTONG & ZAK
        '610.02.04' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA TELEPON, FAX DAN INTERNET
        '610.02.05' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA PULSA HP
        '610.02.07' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA BBM
        '610.02.08' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA EKSPEDISI
        '610.02.10' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA PENGURUSAN DOKUMEN
        '610.02.11' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA PERJALANAN
        '610.02.12' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA KONSULTAN, NOTARIS, ADVOKAT
        '610.02.13' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA RETRIBUSI
        '610.02.14' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA IKLAN
        '610.04.01' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA PEMELIHARAAN INVENTARIS
        '610.04.02' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA PEMELIHARAAN KENDARAAN
        '610.04.03' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA PEMELIHARAAN BANGUNAN
        '610.04.04' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA SPARE PART
        '610.05.01' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA PENYUSUTAN INVENTARIS
        '610.05.02' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA PENYUSUTAN KENDARAAN
        '610.05.03' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA PENYUSUTAN BANGUNAN
        '610.06.02' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA PENGANGKUTAN
        '610.06.03' => ProfitLossSection::OPERATING_EXPENSE,   // BIAYA ASURANSI
        '6310' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA KANTONG & ZAK
        '6410' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA TELEPON, FAX DAN INTERNET
        '6500' => ProfitLossSection::OPERATING_EXPENSE,        // Beban Transport
        '6510' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA BBM
        '6520' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA PENGANGKUTAN
        '6600' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA PEMELIHARAAN INVENTARIS
        '6610' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA PENYUSUTAN INVENTARIS
        '6620' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA PEMELIHARAAN KENDARAAN
        '6621' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA PENYUSUTAN KENDARAAN
        '6630' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA PEMELIHARAAN BANGUNAN
        '6631' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA PENYUSUTAN BANGUNAN
        '6640' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA SPARE PART
        '6700' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA ASURANSI
        '6800' => ProfitLossSection::OPERATING_EXPENSE,        // BIAYA PENGURUSAN DOKUMEN

        // Biaya Usaha Lainnya / Administrasi / Pajak — below-the-line, non-operating.
        '720.01.01' => ProfitLossSection::OTHER_EXPENSE,   // PAJAK BUNGA BANK
        '720.01.02' => ProfitLossSection::OTHER_EXPENSE,   // BIAYA BUNGA PINJAMAN
        '720.01.03' => ProfitLossSection::OTHER_EXPENSE,   // BIAYA ADMINISTRASI BANK
        '720.01.99' => ProfitLossSection::OTHER_EXPENSE,   // BIAYA LAIN-LAIN
        '730.01.01' => ProfitLossSection::OTHER_EXPENSE,   // BIAYA PEMBULATAN
        '7300' => ProfitLossSection::OTHER_EXPENSE,        // BIAYA PEMBULATAN
        '7900' => ProfitLossSection::OTHER_EXPENSE,        // BIAYA LAIN-LAIN
        '8000' => ProfitLossSection::OTHER_EXPENSE,        // BIAYA PAJAK
        '831.01.01' => ProfitLossSection::OTHER_EXPENSE,   // BIAYA PAJAK
    ];

    public function run(): void
    {
        foreach (self::PROFIT_LOSS_MAPPINGS as $code => $section) {
            $account = ChartOfAccount::query()->where('code', $code)->first();

            if (! $account) {
                Log::warning("ReportAccountMappingSeeder: no Chart of Account found for code {$code} — skipped.");

                continue;
            }

            ReportAccountMapping::query()->firstOrCreate(
                ['chart_of_account_id' => $account->id, 'statement_type' => ReportStatementType::PROFIT_LOSS->value],
                ['section' => $section->value],
            );
        }
    }
}
