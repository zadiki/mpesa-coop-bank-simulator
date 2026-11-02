<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\C2BService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessC2BPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $transactionId) {}

    public function handle(C2BService $service): void
    {
        $service->process(Transaction::findOrFail($this->transactionId));
    }
}
