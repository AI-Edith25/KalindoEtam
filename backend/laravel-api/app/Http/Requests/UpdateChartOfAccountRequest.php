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
            // Two levels only: can't set a parent on an account that already has children of its
            // own, and the chosen parent must not itself be a child of something else.
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

                    if ($parent?->parent_id !== null) {
                        $fail('The selected parent account is itself a child account — only two levels are supported.');

                        return;
                    }

                    if ($account?->children()->exists()) {
                        $fail('This account already has child accounts of its own and cannot also become a child account.');

                        return;
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
