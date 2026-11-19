<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnlineGame extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $attributes = ['turn_seconds' => 60, 'mode' => 'casual'];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $hidden = ['white_key', 'black_key', 'white_user_id', 'black_user_id', 'state'];

    protected function casts(): array
    {
        return ['state' => 'array', 'revision' => 'integer', 'turn_seconds' => 'integer',
            'white_seen_at' => 'immutable_datetime', 'black_seen_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'turn_deadline_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'ranked_scored_at' => 'immutable_datetime', 'game_deadline_at' => 'immutable_datetime'];
    }

    public function colorFor(string $key): ?string
    {
        return hash_equals($this->white_key, $key) ? 'white'
            : ($this->black_key !== null && hash_equals($this->black_key, $key) ? 'black' : null);
    }
}
