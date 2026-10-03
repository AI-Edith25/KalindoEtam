<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\CashBankCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChartOfAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ponytail: deliberately NOT whereNull('deleted_at') like StoreChartOfAccountRequest —
            // renaming a live row onto a trashed row's code has no clean resolution the way create
            // does (ChartOfAccountService::create() restores the trashed row itself; there's no
            // single row to restore into here since the live row being updated is a different
            // physical row), and the raw DB unique index still rejects it either way. Leaving this
            // as a plain unique keeps the 422 "already taken" message instead of a raw SQL crash.
            // Add real handling here if renaming onto an archived code turns out to be a real need.
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('chart_of_accounts', 'code')->ignore($this->route('chart_of_account'))],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'account_type' => ['sometimes', 'required', Rule::enum(AccountType::class)],
            'is_active' => ['sometimes', 'boolean'],
            'is_cash_bank' => ['sometimes', 'boolean'],
            'cash_bank_category' => ['nullable', Rule::enum(CashBankCategory::class)],
        ];
    }
}
