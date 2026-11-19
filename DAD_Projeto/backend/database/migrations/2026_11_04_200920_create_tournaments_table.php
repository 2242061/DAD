<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('status')->default('registration')->index();
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('min_rank');
            $table->unsignedInteger('max_rank');
            $table->unsignedInteger('round_minutes');
            $table->unsignedInteger('game_minutes');
            $table->unsignedInteger('current_round')->default(0);
            $table->timestamp('round_started_at')->nullable();
            $table->timestamp('round_deadline_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('awarded_at')->nullable();
            $table->timestamps();
        });
        Schema::create('tournament_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('entry_points');
            $table->unsignedInteger('seed')->nullable();
            $table->timestamps();
            $table->unique(['tournament_id', 'user_id']);
        });
        Schema::table('online_games', function (Blueprint $table) {
            $table->foreignId('tournament_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('tournament_round')->nullable();
            $table->timestamp('game_deadline_at')->nullable();
        });
        Schema::table('rank_entries', function (Blueprint $table) {
            $table->uuid('game_id')->nullable()->change();
            $table->foreignId('tournament_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['tournament_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('rank_entries', function (Blueprint $table) {
            $table->dropUnique(['tournament_id', 'user_id']);
            $table->dropConstrainedForeignId('tournament_id');
        });
        Schema::table('online_games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tournament_id');
            $table->dropColumn(['tournament_round', 'game_deadline_at']);
        });
        Schema::dropIfExists('tournament_participants');
        Schema::dropIfExists('tournaments');
    }
};
