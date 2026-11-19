<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'min_rank' => 'integer', 'max_rank' => 'integer', 'round_minutes' => 'integer', 'game_minutes' => 'integer', 'current_round' => 'integer', 'round_started_at' => 'immutable_datetime', 'round_deadline_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'awarded_at' => 'immutable_datetime'];
    }
}
