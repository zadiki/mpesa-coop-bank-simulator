<?php

namespace App\Services;

use App\Models\ForcedOutcome;
use App\Models\Transaction;
use App\Support\Scenario;
use Illuminate\Support\Facades\DB;

class ScenarioResolver
{
    /** Picks the outcome for a new transaction and consumes a forced outcome if one is queued. */
    public function assign(Transaction $transaction): string
    {
        return DB::transaction(function () use ($transaction) {
            $forced = ForcedOutcome::where('type', $transaction->type)
                ->whereNull('consumed_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($forced) {
                $forced->update(['consumed_at' => now(), 'transaction_id' => $transaction->id]);
                $outcome = $forced->outcome;
            } else {
                $outcome = Scenario::fromMsisdn($transaction->type, $transaction->msisdn) ?? 'success';
            }

            $transaction->update(['scenario' => $outcome]);

            return $outcome;
        });
    }
}
