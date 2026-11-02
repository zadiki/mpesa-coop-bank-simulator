<?php

namespace App\Jobs;

use App\Models\CoopTransfer;
use App\Services\Coop\CoopTransferService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCoopTransfer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $transferId) {}

    public function handle(CoopTransferService $service): void
    {
        $service->process(CoopTransfer::findOrFail($this->transferId));
    }
}
