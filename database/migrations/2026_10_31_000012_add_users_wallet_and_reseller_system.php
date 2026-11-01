<?php

use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'wallet_balance')) {
                $table->decimal('wallet_balance', 12, 2)->default(0)->after('is_admin');
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('wallet_balance');
            }
            if (! Schema::hasColumn('users', 'is_reseller')) {
                $table->boolean('is_reseller')->default(false)->after('is_active')->index();
            }
            if (! Schema::hasColumn('users', 'reseller_slug')) {
                $table->string('reseller_slug', 80)->nullable()->unique()->after('is_reseller');
            }
            if (! Schema::hasColumn('users', 'reseller_discount_percent')) {
                $table->decimal('reseller_discount_percent', 5, 2)->default(0)->after('reseller_slug');
            }
            if (! Schema::hasColumn('users', 'reseller_notes')) {
                $table->text('reseller_notes')->nullable()->after('reseller_discount_percent');
            }
        });

        if (! Schema::hasTable('reseller_product_prices')) {
            Schema::create('reseller_product_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->decimal('custom_price', 12, 2)->nullable();
                $table->decimal('discount_percent', 5, 2)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->string('type', 40)->index(); // topup, deduct, order_payment, refund
                $table->decimal('amount', 12, 2);
                $table->decimal('balance_after', 12, 2);
                $table->string('note')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('payment_methods') && ! app()->runningUnitTests()) {
            PaymentMethod::query()->firstOrCreate(
                ['code' => 'wallet'],
                [
                    'name' => 'Account Wallet Balance (Instant Auto Delivery)',
                    'type' => 'auto',
                    'currency' => 'PKR',
                    'instructions' => 'Instant zero-fee payment deducted directly from your SellMeTools Account Wallet Balance.',
                    'is_active' => true,
                    'sort' => 0,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('reseller_product_prices');

        Schema::table('users', function (Blueprint $table) {
            foreach (['reseller_notes', 'reseller_discount_percent', 'reseller_slug', 'is_reseller', 'is_active', 'wallet_balance'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
