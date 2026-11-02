<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoopTransfer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'charge' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'callback_payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public const STATUSES = ['pending', 'success', 'partial', 'failed', 'no_callback'];

    public const RAILS = ['ift' => 'Internal transfer', 'pesalink' => 'PesaLink', 'mpesa' => 'M-Pesa payout'];

    public function destinations(): HasMany
    {
        return $this->hasMany(CoopTransferDestination::class)->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CallbackAttempt::class)->orderBy('id');
    }
}
