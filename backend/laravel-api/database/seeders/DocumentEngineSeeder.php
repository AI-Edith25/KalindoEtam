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
            ['module' => 'sales', 'document_type' => 'sales', 'prefix' => 'SO-'],
            ['module' => 'sales', 'document_type' => 'delivery', 'prefix' => 'DN-'],
            ['module' => 'invoice', 'document_type' => 'invoice', 'prefix' => 'INV-'],
            ['module' => 'invoice', 'document_type' => 'credit_note', 'prefix' => 'CN-'],
            // 'DN-' is already Delivery's prefix — 'DBN-' avoids the collision.
            ['module' => 'invoice', 'document_type' => 'debit_note', 'prefix' => 'DBN-'],
            ['module' => 'journal', 'document_type' => 'journal', 'prefix' => 'JE-'],
            ['module' => 'finance', 'document_type' => 'payment', 'prefix' => 'PAY-'],
            ['module' => 'finance', 'document_type' => 'receipt', 'prefix' => 'REC-'],
            ['module' => 'inventory', 'document_type' => 'stock_adjustment', 'prefix' => 'ADJ-'],
        ];

        foreach ($series as $row) {
            NamingSeries::query()->firstOrCreate(
                ['document_type' => $row['document_type'], 'is_default' => true],
                [
                    'module' => $row['module'],
                    'prefix' => $row['prefix'],
                    'digit_length' => 5,
                    'current_number' => 0,
                    'is_active' => true,
                ]
            );
        }
    }
}
