<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\CashBankCategory;
use App\Models\ChartOfAccount;
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
            // whereNull('deleted_at'): a renamed-away/deleted code shouldn't permanently block
            // reuse any more than it does for Create — see ChartOfAccountService::update(),
            // which force-deletes the conflicting trashed row (not restores it; the live row
            // being updated is a different physical row, so there's nothing to restore into)
            // before applying the rename, so the raw DB unique index agrees with this validation.
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('chart_of_accounts', 'code')->ignore($this->route('chart_of_account'))->whereNull('deleted_at')],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'account_type' => ['sometimes', 'required', Rule::enum(AccountType::class)],
            'is_active' => ['sometimes', 'boolean'],
            'is_cash_bank' => ['sometimes', 'boolean'],
            'cash_bank_category' => ['nullable', Rule::enum(CashBankCategory::class)],
            // Arbitrary depth is allowed — an account may both have a parent and have children
            // of its own. The one thing still forbidden is a cycle (an account becoming its own
            // ancestor), checked by walking up the candidate parent's own parent chain.
            'parent_id' => [
                'nullable',
                'uuid',
                Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at'),
                Rule::notIn([$this->route('chart_of_account')?->id]),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! $value) {
                        return;
                    }

                    $account = $this->route('chart_of_account');
                    $parent = ChartOfAccount::query()->find($value);

                    $ancestor = $parent;
                    while ($ancestor !== null) {
                        if ($ancestor->id === $account?->id) {
                            $fail('The selected parent account is a descendant of this account — that would create a circular hierarchy.');

                            return;
                        }
                        $ancestor = $ancestor->parent_id ? ChartOfAccount::query()->find($ancestor->parent_id) : null;
                    }

                    $newAccountType = $this->input('account_type') ?? $account?->account_type->value;
                    if ($parent !== null && $newAccountType !== null && $parent->account_type->value !== $newAccountType) {
                        $fail("The selected parent account is a {$parent->account_type->value} account and cannot have a child of a different account type.");

                        return;
                    }

                    // Same ambiguous-balance concern as the check below, from the other side: the
                    // PARENT being assigned here would become a header for the first time if it
                    // already carries its own posted Journal Entry lines. A parent that already has
                    // other children can never hit this (point 1's backend guard means it can never
                    // have posted lines of its own once it's a header), so this only ever fires on a
                    // genuine first-time promotion.
                    if ($parent !== null && $parent->journalEntryLines()->exists()) {
                        $fail("The selected parent account (\"{$parent->code} - {$parent->name}\") already has posted Journal Entry lines and cannot become a header/parent account.");

                        return;
                    }

                    // Only a genuine re-parent (the account didn't already sit under this exact
                    // parent) is blocked — an unchanged parent_id on an untouched save is a no-op,
                    // not a "naik status" that could orphan already-posted transactions into an
                    // ambiguous direct-vs-rollup balance.
                    if ($account !== null && $value !== $account->parent_id && $account->journalEntryLines()->exists()) {
                        $fail('This account already has posted Journal Entry lines and cannot be assigned a parent — move or reverse those transactions first.');
                    }
                },
            ],
        ];
    }
}
