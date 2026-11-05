<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallbackAttempt extends Model
{
    protected $guarded = [];

    protected $casts = ['request_body' => 'array'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function coopTransfer(): BelongsTo
    {
        return $this->belongsTo(CoopTransfer::class);
    }

    public function coopCollection(): BelongsTo
    {
        return $this->belongsTo(CoopCollection::class);
    }

    public function succeeded(): bool
    {
        return $this->http_status !== null && $this->http_status >= 200 && $this->http_status < 300;
    }
}
