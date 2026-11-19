<?php

namespace Database\Factories;

use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WalletTransactionFactory extends Factory
{
    public function definition(): array
    {
        return ['wallet_id' => Wallet::factory(), 'idempotency_key' => Str::uuid()->toString(), 'request_hash' => str_repeat('a', 64), 'kind' => 'deposit', 'status' => 'processing', 'method' => 'MBWAY', 'reference' => '915785345', 'masked_reference' => '•••• 5345',
            'amount_euros' => 10, 'exchange_rate' => 100, 'fee_bps' => 1000, 'gross_coins' => 1000, 'fee_coins' => 100, 'net_coins' => 900, 'debit_coins' => 0];
    }
}
