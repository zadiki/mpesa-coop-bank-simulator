<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_own' => 'boolean',
        'balance' => 'decimal:2',
        'blocked_amount' => 'decimal:2',
        'od_limit' => 'decimal:2',
    ];

    public const COOP_BANK_CODE = '11';

    public const STATUSES = ['active', 'dormant', 'closed', 'blocked'];

    /** Hidden from casual dumps: the password the IPN is sent with. */
    protected $hidden = ['ipn_password'];

    public function lines(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CoopCollection::class);
    }

    /** What can actually be spent: booked balance minus holds plus overdraft. */
    public function available(): float
    {
        return (float) $this->balance - (float) $this->blocked_amount + (float) $this->od_limit;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public static function normalizeBankCode(?string $code): string
    {
        $code = ltrim((string) $code, '0');

        return $code === '' ? self::COOP_BANK_CODE : $code;
    }

    public static function find11(string $accountNumber): ?self
    {
        return static::where('bank_code', self::COOP_BANK_CODE)->where('account_number', $accountNumber)->first();
    }
}
