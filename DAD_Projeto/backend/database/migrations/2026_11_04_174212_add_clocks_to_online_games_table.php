<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_games', function (Blueprint $table) {
            $table->unsignedInteger('turn_seconds')->default(60);
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('turn_deadline_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_games', fn (Blueprint $table) => $table->dropColumn(['turn_seconds', 'started_at', 'turn_deadline_at', 'finished_at']));
    }
};
