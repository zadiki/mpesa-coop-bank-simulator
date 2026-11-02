<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shortcode extends Model
{
    protected $guarded = [];

    protected $casts = ['balance' => 'decimal:2', 'validation_enabled' => 'boolean'];

    public const DEFAULT_B2C_BALANCE = 1_000_000;

    public function registeredUrl(): HasOne
    {
        return $this->hasOne(RegisteredUrl::class, 'shortcode', 'shortcode');
    }

    /** Shortcodes are auto-created on first use so client apps work without setup. */
    public static function resolve(string $shortcode, string $type): self
    {
        return static::firstOrCreate(
            ['shortcode' => $shortcode],
            ['type' => $type, 'balance' => $type === 'b2c' ? self::DEFAULT_B2C_BALANCE : 0],
        );
    }
}
