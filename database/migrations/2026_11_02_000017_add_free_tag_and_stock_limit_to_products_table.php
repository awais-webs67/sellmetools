<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'is_free')) {
                $table->boolean('is_free')->default(false)->after('is_featured');
            }
            if (! Schema::hasColumn('products', 'stock_limit')) {
                $table->unsignedInteger('stock_limit')->nullable()->after('available_stock');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $drop = array_filter(['is_free', 'stock_limit'], fn (string $col) => Schema::hasColumn('products', $col));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};

