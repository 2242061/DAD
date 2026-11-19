<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('rank_points')->default(0)->index();
        });
        Schema::table('online_games', function (Blueprint $table) {
            $table->string('mode')->default('casual')->index();
            $table->timestamp('ranked_scored_at')->nullable();
        });
        Schema::create('rank_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('game_id')->constrained('online_games')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('outcome');
            $table->integer('delta');
            $table->unsignedInteger('previous_points');
            $table->unsignedInteger('resulting_points');
            $table->timestamps();
            $table->unique(['game_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_entries');
        Schema::table('online_games', fn (Blueprint $table) => $table->dropColumn(['mode', 'ranked_scored_at']));
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['rank_points']);
            $table->dropColumn('rank_points');
        });
    }
};
