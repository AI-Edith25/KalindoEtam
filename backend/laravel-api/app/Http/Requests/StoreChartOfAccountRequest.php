<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\CashBankCategory;
use App\Models\ChartOfAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChartOfAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // whereNull('deleted_at') — chart_of_accounts uses SoftDeletes and the list/search UI
            // already excludes trashed rows via Eloquent's global scope; without this, a code once
            // soft-deleted (e.g. the 2026-09-27 unused-cash/bank cleanup) stays permanently blocked
            // from reuse even though nothing on screen shows it as taken.
            'code' => ['required', 'string', 'max:20', Rule::unique('chart_of_accounts', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'is_active' => ['sometimes', 'boolean'],
            'is_cash_bank' => ['sometimes', 'boolean'],
            'cash_bank_category' => ['nullable', Rule::enum(CashBankCategory::class)],
            // Arbitrary depth is allowed — the chosen parent may itself be a child of
            // something else (a brand-new account can never be its own ancestor, so no cycle
            // check is needed here; see UpdateChartOfAccountRequest for that).
            'parent_id' => [
                'nullable',
                'uuid',
                Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at'),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! $value) {
                        return;
                    }

                    $parent = ChartOfAccount::query()->find($value);

                    if ($parent !== null && $this->input('account_type') !== null && $parent->account_type->value !== $this->input('account_type')) {
                        $fail("The selected parent account is a {$parent->account_type->value} account and cannot have a child of a different account type.");

                        return;
                    }

                    // A parent that already carries its own posted Journal Entry lines would become
                    // a header with an ambiguous direct-vs-rollup balance the moment this child is
                    // attached — see UpdateChartOfAccountRequest's identical check for the reverse
                    // direction (a child account being given this same parent later).
                    if ($parent !== null && $parent->journalEntryLines()->exists()) {
                        $fail("The selected parent account (\"{$parent->code} - {$parent->name}\") already has posted Journal Entry lines and cannot become a header/parent account.");
                    }
                },
            ],
        ];
    }
}
