<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'legacy_slugs')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->json('legacy_slugs')->nullable()->after('slug');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'legacy_slugs')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('legacy_slugs');
            });
        }
    }
};

