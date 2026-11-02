<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'callback_payload' => 'array',
    ];

    public const STATUSES = ['pending', 'completed', 'failed', 'rejected', 'timeout', 'no_callback'];

    public function attempts(): HasMany
    {
        return $this->hasMany(CallbackAttempt::class)->orderBy('id');
    }
}
