<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_sources')) {
            Schema::table('product_sources', function (Blueprint $table): void {
                if (! Schema::hasColumn('product_sources', 'pricing_mode')) {
                    $table->string('pricing_mode', 20)->nullable()->after('is_active');
                }
                if (! Schema::hasColumn('product_sources', 'markup_value')) {
                    $table->decimal('markup_value', 12, 2)->nullable()->after('pricing_mode');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_sources')) {
            Schema::table('product_sources', function (Blueprint $table): void {
                if (Schema::hasColumn('product_sources', 'markup_value')) {
                    $table->dropColumn('markup_value');
                }
                if (Schema::hasColumn('product_sources', 'pricing_mode')) {
                    $table->dropColumn('pricing_mode');
                }
            });
        }
    }
};
