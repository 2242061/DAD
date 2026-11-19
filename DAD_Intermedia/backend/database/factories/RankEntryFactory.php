<?php

namespace Database\Factories;

use App\Models\OnlineGame;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RankEntryFactory extends Factory
{
    public function definition(): array
    {
        return ['game_id' => OnlineGame::factory(), 'user_id' => User::factory(), 'outcome' => 'win', 'delta' => 30, 'previous_points' => 0, 'resulting_points' => 30];
    }
}
