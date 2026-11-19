<?php

namespace Database\Factories;

use App\Services\CheckersEngine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OnlineGameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(Str::random(10)), 'white_key' => hash('sha256', Str::random(64)),
            'phase' => 'waiting', 'revision' => 0, 'state' => (new CheckersEngine)->initial(),
        ];
    }
}
