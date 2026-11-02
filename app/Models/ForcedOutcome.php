<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForcedOutcome extends Model
{
    protected $guarded = [];

    protected $casts = ['consumed_at' => 'datetime'];
}
