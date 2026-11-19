<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $hidden = ['reference', 'request_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return ['reference' => 'encrypted', 'amount_euros' => 'integer', 'exchange_rate' => 'integer', 'fee_bps' => 'integer', 'gross_coins' => 'integer', 'fee_coins' => 'integer', 'net_coins' => 'integer', 'debit_coins' => 'integer', 'finished_at' => 'immutable_datetime'];
    }
}
