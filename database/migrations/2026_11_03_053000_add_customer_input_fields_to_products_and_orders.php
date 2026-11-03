<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'customer_input_label')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('customer_input_label', 500)->nullable()->after('delivery_type');
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'customer_input')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->text('customer_input')->nullable()->after('admin_note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'customer_input_label')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('customer_input_label');
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'customer_input')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('customer_input');
            });
        }
    }
};

