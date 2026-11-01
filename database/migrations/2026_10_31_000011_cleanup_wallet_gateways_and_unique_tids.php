<?php

use App\Models\Order;
use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove separate PayFast / Paymob / Cryptomus rows if they have no orders,
        // because wallets (Easypaisa, JazzCash, NayaPay, SadaPay, Binance) handle gateway linking directly.
        if (Schema::hasTable('payment_methods') && Schema::hasTable('orders')) {
            $usedMethodIds = Order::query()->whereNotNull('payment_method_id')->pluck('payment_method_id')->unique()->all();

            PaymentMethod::query()
                ->whereIn('code', ['payfast', 'paymob', 'cryptomus'])
                ->whereNotIn('id', $usedMethodIds)
                ->delete();
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->index('transaction_id', 'payments_tid_global_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex('payments_tid_global_idx');
            });
        }
    }
};
