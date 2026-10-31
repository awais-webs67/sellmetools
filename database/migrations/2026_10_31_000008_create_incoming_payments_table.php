<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_payments', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->nullable()->index(); // easypaisa, jazzcash, nayapay, sadapay, binance, sms
            $table->string('transaction_id', 100)->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('PKR');
            $table->string('sender', 100)->nullable();
            $table->text('raw_message')->nullable();
            $table->foreignId('matched_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_payments');
    }
};
