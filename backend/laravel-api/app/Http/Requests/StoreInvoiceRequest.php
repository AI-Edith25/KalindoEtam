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
            // The printed/displayed Location — optional on every flow. Defaults server-side
            // (InvoiceService::create()) from the anchor Delivery's warehouse, or from warehouse_id
            // for Direct Goods, when omitted. See Invoice::locationWarehouse().
            'location_warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            // Transportation and Direct Goods only — picked directly instead of being derived from a Delivery.
            'customer_id' => [Rule::when($isTransportationOrDirectGoods, 'required', 'prohibited'), 'uuid', 'exists:customers,id'],
            // Transportation and Direct Goods only — no Sales Order to derive Branch from, so it's captured directly here.
            'branch_id' => [Rule::when($isTransportationOrDirectGoods, 'required', 'prohibited'), 'uuid', 'exists:branches,id'],
            // Transportation: manual, freestanding lines (no Item/inventory link). Direct Goods:
            // real Item-backed lines, same master data every Sales Order line resolves against.
            'items' => [Rule::when($isTransportationOrDirectGoods, 'required', 'prohibited'), 'array', 'min:1'],
            'items.*.description' => [Rule::when($isTransportation, 'required', 'prohibited'), 'string'],
            // Transportation only — the MiscellaneousItem's own UOM, carried along for display/print.
            // Direct Goods derives its UOM from the Item master instead, never from the request.
            'items.*.uom' => [Rule::when($isTransportation, 'nullable', 'prohibited'), 'string', 'max:50'],
            'items.*.item_id' => [Rule::when($isDirectGoods, 'required', 'prohibited'), 'uuid', 'exists:items,id'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            // Transportation and Direct Goods only — per-line discount, replacing the old header
            // field (discount is per-item now, never a document-wide figure).
            'items.*.discount_type' => [Rule::when($isTransportationOrDirectGoods, 'nullable', 'prohibited'), Rule::enum(DiscountType::class)],
            'items.*.discount_value' => [Rule::when($isTransportationOrDirectGoods, 'nullable', 'prohibited'), 'numeric', 'min:0'],
            // Transportation and Direct Goods only — an optional per-line override of the Item's
            // own default sales tax (Direct Goods) or a plain manual pick (Transportation, which
            // previously had only a single header-level tax select), same TaxService::
            // resolveLineTax() contract Sales Order/Delivery lines already use.
            'items.*.tax_id' => [Rule::when($isTransportationOrDirectGoods, 'nullable', 'prohibited'), 'uuid', Rule::exists('taxes', 'id')->where('is_active', true)],
            // Drives which Naming Series generates document_number — see Invoice::documentType().
            // Direct Goods stays 'goods' on purpose (Invoice::isDirectGoods() distinguishes it via
            // warehouse_id instead), so it shares the exact same invoice_goods series as a
            // Delivery-based Goods invoice.
            'invoice_type' => ['required', Rule::enum(InvoiceType::class)],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'terms_of_payment_id' => ['nullable', 'uuid', 'exists:terms_of_payments,id'],
            // Discount is per-line only now (never a document-wide figure) — these three fields
            // are no longer valid client input; discount_amount on the created Invoice is always
            // derived as the sum of its lines' own discount_amount.
            'discount_type' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'discount_percentage' => ['prohibited'],
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
