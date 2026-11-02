<?php

namespace App\Services\Coop;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Support\Coop;
use Carbon\Carbon;

class CoopEnquiryService
{
    /** Resolves an account the client may query, or an error body. @return array{0: ?BankAccount, 1: ?array} */
    public function ownAccount(string $ref, string $accountNumber): array
    {
        $account = BankAccount::find11($accountNumber);

        return match (true) {
            ! $account => [null, Coop::envelope($ref, '-1')],
            ! $account->is_own => [null, Coop::envelope($ref, '-8')],
            default => [$account, null],
        };
    }

    public function balance(string $ref, BankAccount $a): array
    {
        $uncleared = 0.0;

        return array_merge(Coop::envelope($ref, '0'), [
            'AccountNumber' => $a->account_number,
            'AccountName' => $a->account_name,
            'Currency' => $a->currency,
            'ProductName' => $a->product_name,
            'ClearedBalance' => (float) $a->balance - $uncleared,
            'BookedBalance' => (float) $a->balance,
            'BlockedBalance' => (float) $a->blocked_amount,
            'AvailableBalance' => round($a->available(), 2),
            'ArrearsAmount' => 0.0,
            'BranchName' => $a->branch_name,
            'BranchSortCode' => $a->branch_sort_code,
            'AverageBalance' => (float) $a->balance,
            'UnclearedBalance' => $uncleared,
            'ODLimit' => (float) $a->od_limit,
            'CreditLimit' => 0.0,
        ]);
    }

    public function miniStatement(string $ref, BankAccount $a): array
    {
        $lines = $a->lines()->orderByDesc('transaction_date')->orderByDesc('id')->limit(10)->get();

        return array_merge(Coop::envelope($ref, '0'), [
            'AccountNumber' => $a->account_number,
            'AccountName' => $a->account_name,
            'Transactions' => $lines->map(fn ($l) => $this->line($l, $a))->all(),
        ]);
    }

    public function fullStatement(string $ref, BankAccount $a, Carbon $start, Carbon $end, int $page, int $size): array
    {
        $query = $a->lines()
            ->whereBetween('transaction_date', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->orderBy('transaction_date')->orderBy('id');

        $total = (clone $query)->count();
        $lines = $query->forPage($page, $size)->get();

        $before = $a->lines()->where('transaction_date', '<', $start->copy()->startOfDay())->orderByDesc('transaction_date')->orderByDesc('id')->first();
        $last = $a->lines()->where('transaction_date', '<=', $end->copy()->endOfDay())->orderByDesc('transaction_date')->orderByDesc('id')->first();
        $opening = (float) ($before?->running_balance ?? 0);

        return array_merge(Coop::envelope($ref, '0'), [
            'AccountNumber' => $a->account_number,
            'AccountName' => $a->account_name,
            'Currency' => $a->currency,
            'StartDate' => $start->toDateString(),
            'EndDate' => $end->toDateString(),
            'OpeningBalance' => $opening,
            'ClosingBalance' => (float) ($last?->running_balance ?? $opening),
            'PageNumber' => $page,
            'PageSize' => $size,
            'TotalPages' => max(1, (int) ceil($total / $size)),
            'TotalRecords' => $total,
            'Transactions' => $lines->map(fn ($l) => $this->line($l, $a))->all(),
        ]);
    }

    public function validateAccount(string $ref, string $accountNumber, ?string $bankIdentifier): array
    {
        $account = BankAccount::where('bank_code', BankAccount::normalizeBankCode($bankIdentifier))
            ->where('account_number', $accountNumber)->first();

        if (! $account) {
            return [400, Coop::envelope($ref, '-1')];
        }
        if (! $account->isActive()) {
            return [400, Coop::envelope($ref, '-6', 'ACCOUNT INACTIVE: '.strtoupper($account->status))];
        }

        return [200, array_merge(Coop::envelope($ref, '0'), [
            'AccountNumber' => $account->account_number,
            'AccountName' => $account->account_name,
            'BankCode' => $account->bank_code,
            'Currency' => $account->currency,
        ])];
    }

    public function line(BankTransaction $l, BankAccount $a): array
    {
        return [
            'TransactionID' => $l->transaction_id,
            'TransactionDate' => $l->transaction_date->format('Y-m-d\TH:i:s'),
            'ValueDate' => $l->value_date->toDateString(),
            'Narration' => $l->narration,
            'TransactionType' => $l->type,
            'ServicePoint' => $l->service_point,
            'TransactionReference' => $l->transaction_reference,
            'CreditAmount' => $l->type === 'C' ? (float) $l->amount : 0.0,
            'DebitAmount' => $l->type === 'D' ? (float) $l->amount : 0.0,
            'RunningClearedBalance' => (float) $l->running_balance,
            'RunningBookBalance' => (float) $l->running_balance,
            'DebitLimit' => (float) $a->od_limit,
            'LimitExpiryDate' => '',
        ];
    }
}
