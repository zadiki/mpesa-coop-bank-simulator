<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessToken extends Model
{
    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime'];

    public function app(): BelongsTo
    {
        return $this->belongsTo(DarajaApp::class, 'daraja_app_id');
    }
}
