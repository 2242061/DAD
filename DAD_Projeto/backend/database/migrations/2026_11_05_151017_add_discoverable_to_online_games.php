<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_games', function (Blueprint $table) {
            $table->boolean('discoverable')->default(false);
            $table->index(['discoverable', 'phase', 'created_at']);
        });

        DB::table('online_games')->where('mode', 'ranked')->where('phase', 'waiting')->update(['entry_fee' => 0]);
    }

    public function down(): void
    {
        Schema::table('online_games', function (Blueprint $table) {
            $table->dropIndex(['discoverable', 'phase', 'created_at']);
            $table->dropColumn('discoverable');
        });
    }
};
