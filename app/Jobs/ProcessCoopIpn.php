<?php

namespace App\Jobs;

use App\Models\CoopCollection;
use App\Services\Coop\CoopCollectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Send the payment notification (IPN) for money received into an own Co-op account. */
class ProcessCoopIpn implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $collectionId) {}

    public function handle(CoopCollectionService $service): void
    {
        $service->notify(CoopCollection::findOrFail($this->collectionId));
    }
}
