<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('online_games', function (Blueprint $table) {
            $table->dateTime('white_seen_at', 3)->nullable();
            $table->dateTime('black_seen_at', 3)->nullable();
        });

        // Retire invitations created under the previous free-entry policy.
        DB::table('online_games')->where('mode', 'ranked')->where('phase', 'waiting')
            ->where('entry_fee', 0)->eachById(function (object $game): void {
                $state = json_decode($game->state, true, flags: JSON_THROW_ON_ERROR);
                $state['status'] = 'finished';
                $state['winner'] = null;
                $state['reason'] = 'cancelled';
                $state['revision'] = $game->revision + 1;
                DB::table('online_games')->where('id', $game->id)->where('phase', 'waiting')->where('revision', $game->revision)
                    ->update(['phase' => 'finished', 'revision' => $state['revision'], 'state' => json_encode($state), 'finished_at' => now()]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('online_games', function (Blueprint $table) {
            $table->dropColumn(['white_seen_at', 'black_seen_at']);
        });
    }
};
