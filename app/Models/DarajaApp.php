<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DarajaApp extends Model
{
    protected $guarded = [];

    public function tokens(): HasMany
    {
        return $this->hasMany(AccessToken::class);
    }
}
