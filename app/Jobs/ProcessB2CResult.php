<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\B2CService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessB2CResult implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $transactionId) {}

    public function handle(B2CService $service): void
    {
        $service->process(Transaction::findOrFail($this->transactionId));
    }
}
