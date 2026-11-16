<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'seo_backup')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->json('seo_backup')->nullable()->after('meta_keywords');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'seo_backup')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('seo_backup');
            });
        }
    }
};

