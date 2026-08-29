<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoicePrintSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'paper_type' => $this->paper_type,
            'orientation' => $this->orientation,
            'margins' => $this->margins,
            'scale_percent' => $this->scale_percent,
            'font_family' => $this->font_family,
            'font_size' => $this->font_size,
            'qty_decimals' => $this->qty_decimals,
            'price_decimals' => $this->price_decimals,
            'amount_decimals' => $this->amount_decimals,
            'number_format' => $this->number_format,
            'show_currency_symbol' => $this->show_currency_symbol,
            'show_discount' => $this->show_discount,
            'visible_columns' => $this->visible_columns,
            'show_logo' => $this->show_logo,
            'show_address' => $this->show_address,
            'show_phone' => $this->show_phone,
            'show_email' => $this->show_email,
            'footer_notes' => $this->footer_notes,
            'show_signature_block' => $this->show_signature_block,
            'signature_left_label' => $this->signature_left_label,
            'signature_right_label' => $this->signature_right_label,
            'show_page_number' => $this->show_page_number,
            'updated_at' => $this->updated_at,
        ];
    }
}
