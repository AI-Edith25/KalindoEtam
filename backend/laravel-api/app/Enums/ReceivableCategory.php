<?php

namespace App\Enums;

/**
 * The single place Customer's category maps to a COA control account / NamingSeries document
 * type / code prefix — never duplicated in Customer, PaymentEntry, or ReceiptEntry. See
 * docs/superpowers/specs/2026-10-10-customer-receivable-categories-design.md.
 */
enum ReceivableCategory: string
{
    case TRADE = 'C';
    case EMPLOYEE = 'PK';
    case OTHER = 'PL';

    /** The Piutang control account (ChartOfAccountsSeeder) this category's transactions post to. */
    public function accountCode(): string
    {
        return match ($this) {
            self::TRADE => '112.01',
            self::EMPLOYEE => '112.02',
            self::OTHER => '112.03',
        };
    }

    /** The NamingSeries document_type this category's customer codes are generated from. */
    public function namingSeriesDocumentType(): string
    {
        return match ($this) {
            self::TRADE => 'customer',
            self::EMPLOYEE => 'customer_pk',
            self::OTHER => 'customer_pl',
        };
    }

    /** The code prefix this category's NamingSeries row uses — only for scanning existing codes (see the seeding migration); the generator itself reads the prefix from the NamingSeries row, not from here. */
    public function codePrefix(): string
    {
        return match ($this) {
            self::TRADE => 'C-',
            self::EMPLOYEE => 'PK-',
            self::OTHER => 'PL-',
        };
    }
}
