<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Singleton row — see InvoicePrintSettingRepository::current(). */
class InvoicePrintSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'paper_type',
        'orientation',
        'margins',
        'scale_percent',
        'font_family',
        'font_size',
        'qty_decimals',
        'price_decimals',
        'amount_decimals',
        'number_format',
        'show_currency_symbol',
        'show_discount',
        'visible_columns',
        'show_logo',
        'show_address',
        'show_phone',
        'show_email',
        'footer_notes',
        'show_signature_block',
        'signature_left_label',
        'signature_right_label',
        'show_page_number',
        'updated_by',
    ];

    protected $casts = [
        'margins' => 'array',
        'scale_percent' => 'integer',
        'qty_decimals' => 'integer',
        'price_decimals' => 'integer',
        'amount_decimals' => 'integer',
        'show_currency_symbol' => 'boolean',
        'show_discount' => 'boolean',
        'visible_columns' => 'array',
        'show_logo' => 'boolean',
        'show_address' => 'boolean',
        'show_phone' => 'boolean',
        'show_email' => 'boolean',
        'show_signature_block' => 'boolean',
        'show_page_number' => 'boolean',
    ];
}
