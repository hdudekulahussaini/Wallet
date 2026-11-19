<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('account_holder_name', 150);
            $table->text('account_number')->comment('Encrypted bank account number');
            $table->string('account_number_last4', 4);
            $table->string('account_number_hash', 64);
            $table->string('ifsc_code', 11);
            $table->string('bank_name', 150);
            $table->string('branch_name', 150)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->string('verification_status', 30)->default('pending')->comment('pending, verified, failed');
            $table->string('verification_reference_id', 100)->nullable();
            $table->string('name_at_bank', 150)->nullable();
            $table->string('cashfree_beneficiary_id', 50)->nullable()->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'account_number_hash']);
            $table->index(['user_id', 'is_verified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
