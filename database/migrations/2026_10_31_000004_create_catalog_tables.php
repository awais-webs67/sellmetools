<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->text('warranty')->nullable();
            $table->string('image')->nullable();

            // Pricing: fixed | markup_amount | markup_percent
            $table->string('pricing_mode', 20)->default('fixed');
            $table->decimal('fixed_price', 12, 2)->nullable();
            $table->decimal('markup_value', 12, 2)->default(0);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->decimal('price', 12, 2)->default(0); // computed selling price (PKR)
            $table->decimal('cost_price', 12, 2)->nullable(); // current cheapest cost (PKR)

            // Denormalised stock snapshot for fast listing / live polling.
            // Authoritative check happens under row lock at order time.
            $table->unsignedInteger('available_stock')->default(0);
            $table->boolean('stock_unlimited')->default(false);

            $table->boolean('is_active')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->string('disabled_reason')->nullable(); // set when auto-disabled by sync
            $table->unsignedInteger('max_per_order')->default(5);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('sold_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort']);
        });

        Schema::create('product_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_product_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'supplier_product_id']);
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->text('content'); // encrypted cast
            $table->string('status', 20)->default('available'); // available | reserved | sold
            $table->unsignedBigInteger('order_item_id')->nullable()->index();
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('product_sources');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
