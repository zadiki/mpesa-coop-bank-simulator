<?php

namespace App\Services\Coop;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Support\Coop;
use Illuminate\Support\Facades\DB;

/** The only place account balances change, so every movement has a statement line. */
class BankLedger
{
    /** Caller must hold a row lock on $account (lockForUpdate inside a DB transaction). */
    public function post(BankAccount $account, string $type, float $amount, string $narration, ?string $reference, ?int $transferId = null, string $servicePoint = 'API'): BankTransaction
    {
        $account->balance = round((float) $account->balance + ($type === 'C' ? $amount : -$amount), 2);
        $account->save();

        return $account->lines()->create([
            'coop_transfer_id' => $transferId,
            'type' => $type,
            'amount' => $amount,
            'running_balance' => $account->balance,
            'narration' => mb_substr($narration, 0, 250),
            'transaction_reference' => $reference,
            'transaction_id' => Coop::transactionId(),
            'service_point' => $servicePoint,
            'transaction_date' => now(),
            'value_date' => today(),
        ]);
    }

    /** Manual deposit / withdrawal from the UI. */
    public function adjust(BankAccount $account, string $type, float $amount, string $narration): BankTransaction
    {
        return DB::transaction(function () use ($account, $type, $amount, $narration) {
            $locked = BankAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            return $this->post($locked, $type, $amount, $narration, 'SIM-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)), null, 'BRANCH');
        });
    }
}
