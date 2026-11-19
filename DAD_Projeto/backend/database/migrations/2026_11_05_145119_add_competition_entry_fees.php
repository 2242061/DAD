<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['online_games', 'tournaments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedInteger('entry_fee')->default(0));
        }
    }

    public function down(): void
    {
        foreach (['online_games', 'tournaments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('entry_fee'));
        }
    }
};
