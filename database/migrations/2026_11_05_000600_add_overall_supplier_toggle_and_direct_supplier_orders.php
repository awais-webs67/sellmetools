<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'use_overall_supplier_markup')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('use_overall_supplier_markup')->default(true)->after('markup_value');
            });
        }

        if (Schema::hasTable('supplier_orders')) {
            Schema::table('supplier_orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('order_item_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'use_overall_supplier_markup')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('use_overall_supplier_markup');
            });
        }
    }
};

