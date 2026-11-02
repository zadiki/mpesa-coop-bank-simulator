<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SimSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::find($key)?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    public static function callbackDelay(): int
    {
        return (int) static::get('callback_delay_seconds', config('simulator.callback_delay_seconds'));
    }
}
