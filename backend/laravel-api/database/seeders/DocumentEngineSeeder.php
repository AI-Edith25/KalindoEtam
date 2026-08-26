<?php

namespace Database\Seeders;

use App\Models\NamingSeries;
use Illuminate\Database\Seeder;

class DocumentEngineSeeder extends Seeder
{
    /**
     * Baseline NamingSeries so the first module built on top of the
     * Document Engine (Purchase/Sales/Invoice/Journal) can generate
     * numbers immediately. Specific sub-doctypes (e.g. Purchase Order vs
     * Purchase Receipt) are added when that module is actually scoped.
     */
    public function run(): void
    {
        $series = [
            ['module' => 'purchase', 'document_type' => 'purchase', 'prefix' => 'PO-'],
            ['module' => 'purchase', 'document_type' => 'goods_receipt', 'prefix' => 'GR-'],
            ['module' => 'purchase', 'document_type' => 'purchase_invoice', 'prefix' => 'PI-'],
            ['module' => 'purchase', 'document_type' => 'purchase_return', 'prefix' => 'PRT-'],
            // UAT follow-up (2026-08-15): format moved to SO/KE/#####/MM/YYYY and DO/KE/#####/MM/YYYY,
            // matching invoice_goods/invoice_transportation below.
            ['module' => 'sales', 'document_type' => 'sales', 'prefix' => 'SO/KE/', 'suffix' => '/{MM}/{YYYY}'],
            ['module' => 'sales', 'document_type' => 'delivery', 'prefix' => 'DO/KE/', 'suffix' => '/{MM}/{YYYY}'],
            // Sprint 2 (Invoice Numbering): Goods and Transportation invoices number independently.
            // UAT review (2026-08-12): format moved to SI/KE/#####/MM/YYYY (Goods) and
            // TR/KE/#####/MM/YYYY (Transportation) — {MM}/{YYYY} tag the generation date only,
            // never a reset boundary (see DocumentNumberGeneratorService::interpolate()).
            ['module' => 'invoice', 'document_type' => 'invoice_goods', 'prefix' => 'SI/KE/', 'suffix' => '/{MM}/{YYYY}'],
            ['module' => 'invoice', 'document_type' => 'invoice_transportation', 'prefix' => 'TR/KE/', 'suffix' => '/{MM}/{YYYY}'],
            ['module' => 'invoice', 'document_type' => 'credit_note', 'prefix' => 'CN-'],
            // 'DN-' is already Delivery's prefix — 'DBN-' avoids the collision.
            ['module' => 'invoice', 'document_type' => 'debit_note', 'prefix' => 'DBN-'],
            ['module' => 'journal', 'document_type' => 'journal', 'prefix' => 'JE-'],
            ['module' => 'finance', 'document_type' => 'payment', 'prefix' => 'PAY-'],
            ['module' => 'finance', 'document_type' => 'receipt', 'prefix' => 'REC-'],
            ['module' => 'inventory', 'document_type' => 'stock_adjustment', 'prefix' => 'ADJ-'],
            ['module' => 'inventory', 'document_type' => 'stock_transfer', 'prefix' => 'TRF-'],
        ];

        foreach ($series as $row) {
            NamingSeries::query()->firstOrCreate(
                ['document_type' => $row['document_type'], 'is_default' => true],
                [
                    'module' => $row['module'],
                    'prefix' => $row['prefix'],
                    'suffix' => $row['suffix'] ?? null,
                    'digit_length' => 5,
                    'current_number' => 0,
                    'is_active' => true,
                ]
            );
        }
    }
}
