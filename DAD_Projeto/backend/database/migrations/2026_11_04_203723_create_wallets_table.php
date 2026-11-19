<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('reserved')->default(0);
            $table->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('request_hash', 64);
            $table->string('kind');
            $table->string('status')->index();
            $table->string('method');
            $table->text('reference');
            $table->string('masked_reference');
            $table->unsignedInteger('amount_euros');
            $table->unsignedInteger('exchange_rate');
            $table->unsignedInteger('fee_bps');
            $table->unsignedBigInteger('gross_coins');
            $table->unsignedBigInteger('fee_coins');
            $table->unsignedBigInteger('net_coins');
            $table->unsignedBigInteger('debit_coins');
            $table->unsignedInteger('gateway_status')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_reason')->nullable();
            $table->timestamps();
            $table->unique(['wallet_id', 'idempotency_key']);
        });
        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('transaction_id')->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->bigInteger('delta');
            $table->unsignedBigInteger('previous_balance');
            $table->unsignedBigInteger('resulting_balance');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_entries');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
