<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentParticipant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['entry_points' => 'integer', 'seed' => 'integer'];
    }
}
