<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoicePrintSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paper_type' => ['sometimes', 'string', 'in:a4,continuous,half'],
            'orientation' => ['sometimes', 'string', 'in:portrait,landscape'],
            'margins' => ['sometimes', 'array'],
            'margins.a4' => ['sometimes', 'array'],
            'margins.a4.top' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'margins.a4.bottom' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'margins.a4.left' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'margins.a4.right' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'margins.continuous' => ['sometimes', 'array'],
            'margins.continuous.top' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'margins.continuous.bottom' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'margins.continuous.left' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'margins.continuous.right' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'margins.half' => ['sometimes', 'array'],
            'margins.half.top' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'margins.half.bottom' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'margins.half.left' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'margins.half.right' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'scale_percent' => ['sometimes', 'integer', 'min:50', 'max:150'],
            'font_family' => ['sometimes', 'string', 'max:255'],
            'font_size' => ['sometimes', 'string', 'in:small,medium,large'],
            'qty_decimals' => ['sometimes', 'integer', 'min:0', 'max:4'],
            'price_decimals' => ['sometimes', 'integer', 'min:0', 'max:4'],
            'amount_decimals' => ['sometimes', 'integer', 'min:0', 'max:4'],
            'number_format' => ['sometimes', 'string', 'in:id,en'],
            'show_currency_symbol' => ['sometimes', 'boolean'],
            'show_discount' => ['sometimes', 'boolean'],
            // Min 1 — the print preview always needs at least one item column left standing.
            'visible_columns' => ['sometimes', 'array', 'min:1'],
            'visible_columns.*' => ['string', 'in:itemCode,description,sales,qty,uom,unitCost,lineAmt'],
            'show_logo' => ['sometimes', 'boolean'],
            'show_address' => ['sometimes', 'boolean'],
            'show_phone' => ['sometimes', 'boolean'],
            'show_email' => ['sometimes', 'boolean'],
            'footer_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'show_signature_block' => ['sometimes', 'boolean'],
            'signature_left_label' => ['sometimes', 'string', 'max:100'],
            'signature_right_label' => ['sometimes', 'string', 'max:100'],
            'show_page_number' => ['sometimes', 'boolean'],
        ];
    }
}
