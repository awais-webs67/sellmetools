<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'delivery_type')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('delivery_type', 40)->default('auto')->after('pricing_mode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'delivery_type')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('delivery_type');
            });
        }
    }
};
