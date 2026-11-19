<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'target_id' => User::factory(),
            'action' => 'profile.updated',
            'details' => ['fields' => ['name']],
        ];
    }
}
