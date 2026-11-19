<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['balance' => 0, 'reserved' => 0];

    protected function casts(): array
    {
        return ['balance' => 'integer', 'reserved' => 'integer'];
    }
}
