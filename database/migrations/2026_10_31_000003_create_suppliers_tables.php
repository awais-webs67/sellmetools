<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver', 50)->default('standard_bot');
            $table->string('base_url');
            $table->text('api_key'); // encrypted cast
            $table->string('currency', 10)->default('USDT');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100); // lower = preferred on equal cost
            $table->decimal('balance', 14, 4)->nullable();
            $table->decimal('low_balance_threshold', 14, 4)->default(5);
            $table->timestamp('balance_alerted_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100);
            $table->string('name');
            $table->decimal('cost', 14, 4); // in supplier currency
            $table->integer('stock')->nullable();
            $table->boolean('unlimited_stock')->default(false);
            $table->boolean('is_available')->default(false);
            $table->text('warranty')->nullable();
            $table->text('description')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'external_id']);
            $table->index('is_available');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('suppliers');
    }
};
