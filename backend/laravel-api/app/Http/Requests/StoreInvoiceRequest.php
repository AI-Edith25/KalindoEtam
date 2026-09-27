<?php

namespace App\Http\Requests;

use App\Enums\DiscountType;
use App\Enums\InvoiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Direct Goods is a third sub-flow of invoice_type=goods (Jumbo & Curah billed straight to
        // a Customer, no Sales Order/Delivery) — distinguished by warehouse_id's presence, the same
        // "a field that's semantically only meaningful for this sub-flow" discriminator
        // GoodsReceiptService::create() already uses for purchase_order_id. See Invoice::
        // isDirectGoods() for the persisted-side equivalent of this same check.
        //
        // Rule::when()'s condition closure is evaluated by the Validator against whatever data is
        // actually being validated (real request input, or a raw array via the validator() test
        // helper) — never against $this->input(), which stays empty for a FormRequest built
        // directly in a unit test (see InvoiceWorkflowTest's direct-instantiation tests).
        $isTransportation = fn ($input) => $input->invoice_type === InvoiceType::TRANSPORTATION->value;
        $isDirectGoods = fn ($input) => $input->invoice_type === InvoiceType::GOODS->value && filled($input->warehouse_id);
        $isDeliveryGoods = fn ($input) => $input->invoice_type === InvoiceType::GOODS->value && ! filled($input->warehouse_id);
        $isTransportationOrDirectGoods = fn ($input) => $isTransportation($input) || $isDirectGoods($input);

        return [
            // Delivery-based Goods only — Direct Goods and Transportation carry no Sales Order/
            // Delivery at all. All selected Deliveries must share the same Customer and be
            // delivered/not-yet-invoiced — enforced in InvoiceService::create() (business logic,
            // not request shape).
            'delivery_ids' => [Rule::when($isDeliveryGoods, 'required', 'prohibited'), 'array', 'min:1'],
            'delivery_ids.*' => ['uuid', 'distinct', 'exists:deliveries,id'],
            // Direct Goods only — no Delivery to inherit a Location from.
            'warehouse_id' => [Rule::when($isDirectGoods, 'required', 'prohibited'), 'uuid', 'exists:warehouses,id'],
            // Transportation and Direct Goods only — picked directly instead of being derived from a Delivery.
            'customer_id' => [Rule::when($isTransportationOrDirectGoods, 'required', 'prohibited'), 'uuid', 'exists:customers,id'],
            // Transportation and Direct Goods only — no Sales Order to derive Branch from, so it's captured directly here.
            'branch_id' => [Rule::when($isTransportationOrDirectGoods, 'required', 'prohibited'), 'uuid', 'exists:branches,id'],
            // Transportation: manual, freestanding lines (no Item/inventory link). Direct Goods:
            // real Item-backed lines, same master data every Sales Order line resolves against.
            'items' => [Rule::when($isTransportationOrDirectGoods, 'required', 'prohibited'), 'array', 'min:1'],
            'items.*.description' => [Rule::when($isTransportation, 'required', 'prohibited'), 'string'],
            'items.*.item_id' => [Rule::when($isDirectGoods, 'required', 'prohibited'), 'uuid', 'exists:items,id'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            // Direct Goods only — an optional per-line override of the Item's own default sales
            // tax, same TaxService::resolveLineTax() contract Sales Order/Delivery lines already use.
            'items.*.tax_id' => [Rule::when($isDirectGoods, 'nullable', 'prohibited'), 'uuid', Rule::exists('taxes', 'id')->where('is_active', true)],
            // Drives which Naming Series generates document_number — see Invoice::documentType().
            // Direct Goods stays 'goods' on purpose (Invoice::isDirectGoods() distinguishes it via
            // warehouse_id instead), so it shares the exact same invoice_goods series as a
            // Delivery-based Goods invoice.
            'invoice_type' => ['required', Rule::enum(InvoiceType::class)],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'terms_of_payment_id' => ['nullable', 'uuid', 'exists:terms_of_payments,id'],
            // Type decides which of the next two fields InvoiceService::resolveDiscount() reads —
            // 'amount cannot exceed subtotal' is enforced there, since subtotal isn't known yet here.
            'discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Only an Active tax may be selected for a new document — docs/TAX_ENGINE_DESIGN.md §9 (Tax Status).
            'tax_id' => ['nullable', 'uuid', Rule::exists('taxes', 'id')->where('is_active', true)],
            // Fallback only — ignored once tax_id resolves to a real Tax (InvoiceService::create()).
            // Kept so a document with no applicable Tax record can still carry a manual figure,
            // the same behavior this field already had before the Tax Engine existed.
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'sales_person_id' => ['nullable', 'uuid', 'exists:sales_persons,id'],
            'reference_1' => ['nullable', 'string', 'max:255'],
            'reference_2' => ['nullable', 'string', 'max:255'],
        ];
    }
}
