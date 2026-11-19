<?php

namespace Database\Factories;

use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletEntryFactory extends Factory
{
    public function definition(): array
    {
        return ['transaction_id' => WalletTransaction::factory(), 'wallet_id' => fn (array $attributes) => WalletTransaction::findOrFail($attributes['transaction_id'])->wallet_id,
            'delta' => 900, 'previous_balance' => 0, 'resulting_balance' => 900];
    }
}
