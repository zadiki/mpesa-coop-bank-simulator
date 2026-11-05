<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\OrgTransferService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessOrgRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $transactionId) {}

    public function handle(OrgTransferService $service): void
    {
        $service->process(Transaction::findOrFail($this->transactionId));
    }
}
