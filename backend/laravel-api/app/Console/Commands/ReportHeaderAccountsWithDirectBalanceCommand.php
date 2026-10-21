<?php

namespace App\Console\Commands;

use App\Models\ChartOfAccount;
use Illuminate\Console\Command;

/**
 * Read-only audit (2026-10-21, COA header-account guardrails work): the backend now refuses any
 * new posting to a header/parent account (JournalEntryService::assertSubledgerRequirement()), but
 * that only guards NEW journal lines going forward. This lists every header account (has children)
 * that still carries posted Journal Entry lines of its own from before the guard existed — exactly
 * the "direct balance on a rollup account" ambiguity flagged in the Trial Balance/report review.
 * Never mutates anything; purely informational, for a manual decision on each row.
 */
class ReportHeaderAccountsWithDirectBalanceCommand extends Command
{
    protected $signature = 'accounts:report-header-balances';

    protected $description = 'List header/parent Chart of Accounts that still have their own posted Journal Entry lines (legacy, pre-guardrail).';

    public function handle(): int
    {
        $headerAccounts = ChartOfAccount::query()
            ->whereHas('children')
            ->with('journalEntryLines')
            ->get()
            ->filter(fn (ChartOfAccount $account) => $account->journalEntryLines->isNotEmpty());

        if ($headerAccounts->isEmpty()) {
            $this->info('No header account has any posted Journal Entry lines of its own. Clean.');

            return self::SUCCESS;
        }

        $this->warn("{$headerAccounts->count()} header account(s) still carry their own posted Journal Entry lines:");
        $this->line('');

        foreach ($headerAccounts as $account) {
            $debit = round((float) $account->journalEntryLines->sum('debit'), 2);
            $credit = round((float) $account->journalEntryLines->sum('credit'), 2);
            $net = $account->isDebitNormal() ? $debit - $credit : $credit - $debit;

            $this->line("{$account->code} - {$account->name} (children: {$account->children()->count()})");
            $this->line("  lines: {$account->journalEntryLines->count()}, debit={$debit}, credit={$credit}, net own balance={$net}");
        }

        return self::SUCCESS;
    }
}
