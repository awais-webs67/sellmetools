<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vouchers')) {
            Schema::create('vouchers', function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('title', 160)->nullable();
                $table->string('discount_type', 20)->default('percentage'); // 'percentage' | 'fixed'
                $table->decimal('discount_value', 10, 2)->default(0);
                $table->string('scope', 20)->default('all'); // 'all' | 'specific'
                $table->json('product_ids')->nullable();
                $table->unsignedInteger('max_uses')->nullable();
                $table->unsignedInteger('max_uses_per_customer')->nullable();
                $table->unsignedInteger('used_count')->default(0);
                $table->decimal('min_order_amount', 10, 2)->nullable();
                $table->decimal('max_discount_amount', 10, 2)->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'voucher_id')) {
                    $table->unsignedBigInteger('voucher_id')->nullable()->after('payment_method_id')->index();
                }
                if (! Schema::hasColumn('orders', 'voucher_code')) {
                    $table->string('voucher_code', 64)->nullable()->after('voucher_id');
                }
                if (! Schema::hasColumn('orders', 'discount_amount')) {
                    $table->decimal('discount_amount', 12, 2)->default(0)->after('voucher_code');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                foreach (['voucher_id', 'voucher_code', 'discount_amount'] as $col) {
                    if (Schema::hasColumn('orders', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('vouchers');
    }
};
