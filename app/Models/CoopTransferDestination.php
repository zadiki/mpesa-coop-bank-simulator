<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoopTransferDestination extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2'];

    public function succeeded(): bool
    {
        return $this->response_code === '0';
    }

    public function label(): string
    {
        return $this->mobile_number ?? trim(($this->bank_code ? "[{$this->bank_code}] " : '').$this->account_number);
    }
}
