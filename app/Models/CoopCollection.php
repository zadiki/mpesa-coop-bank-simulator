<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money that arrived in an own Co-op account, and the payment notification (IPN) sent for it. */
class CoopCollection extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'ipn_payload' => 'array',
    ];

    /** How the money came in. */
    public const CHANNELS = [
        'ift' => 'Transfer from another Co-op account',
        'pesalink' => 'PesaLink from another bank',
        'mpesa' => 'M-Pesa (paybill to bank)',
        'branch' => 'Cash deposit at a branch',
    ];

    public const IPN_STATUSES = [
        'pending' => 'Waiting to be sent',
        'sent' => 'Sent, your app answered 2xx',
        'failed' => 'Sent, your app did not answer 2xx',
        'not_configured' => 'No IPN address on the account',
        'no_ipn' => 'Not sent (forced)',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(CoopTransfer::class, 'coop_transfer_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CallbackAttempt::class)->orderBy('id');
    }

    /** Who paid, in one line. */
    public function payer(): string
    {
        $from = $this->payer_phone ?? trim(($this->payer_bank_code && $this->payer_bank_code !== BankAccount::COOP_BANK_CODE ? "[{$this->payer_bank_code}] " : '').($this->payer_account ?? ''));

        return trim(($this->payer_name ?? '').($from !== '' ? " · {$from}" : '')) ?: 'Unknown payer';
    }
}
