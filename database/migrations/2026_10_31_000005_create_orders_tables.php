<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->unique(); // easypaisa, jazzcash, nayapay, sadapay, binance, bank
            $table->string('type', 20)->default('manual'); // manual | auto
            $table->string('currency', 10)->default('PKR');
            $table->string('account_title')->nullable();
            $table->string('account_number')->nullable();
            $table->text('instructions')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('requires_sender_number')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->string('access_token', 64);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('pending_payment');
            $table->decimal('total', 12, 2);
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->string('currency', 10)->default('PKR');
            $table->decimal('usdt_rate', 10, 4)->nullable(); // rate snapshot at order time
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('email');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->unsignedInteger('reserved_supplier_qty')->default(0);
            $table->unsignedInteger('delivered_qty')->default(0);
            $table->decimal('cost', 12, 2)->nullable(); // actual cost after fulfillment (PKR)
            $table->string('status', 20)->default('pending'); // pending | delivered | partial | failed
            $table->timestamps();
        });

        Schema::create('supplier_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_id', 100)->unique();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('pending'); // pending | success | failed | unknown
            $table->decimal('cost', 14, 4)->nullable(); // supplier currency
            $table->string('external_order_id', 100)->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->text('content'); // encrypted cast
            $table->string('source', 20); // stock | supplier | manual
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('PKR');
            $table->string('transaction_id', 100);
            $table->string('sender_number', 64)->nullable();
            $table->string('screenshot')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            // A transaction ID can only ever be used once per method.
            $table->unique(['payment_method_id', 'transaction_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('supplier_orders');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('payment_methods');
    }
};
