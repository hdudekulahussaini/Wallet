<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20)->comment('credit or debit');
            $table->unsignedBigInteger('amount')->comment('Transaction amount in paise');
            $table->string('reference_id', 100)->unique();
            $table->string('gateway_reference_id', 100)->nullable()->index();
            $table->text('payment_session_id')->nullable();
            $table->string('transaction_type', 50)->comment('deposit, withdrawal, withdrawal_reversal');
            $table->string('status', 30)->default('pending')->comment('pending, processing, completed, failed');
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['wallet_id', 'status']);
            $table->index(['transaction_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
