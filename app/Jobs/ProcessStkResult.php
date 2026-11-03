<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\StkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStkResult implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $transactionId) {}

    public function handle(StkService $service): void
    {
        $service->process(Transaction::findOrFail($this->transactionId));
    }
}
