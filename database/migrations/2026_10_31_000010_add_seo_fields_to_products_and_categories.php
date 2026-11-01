<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'meta_title')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('meta_title')->nullable()->after('warranty');
                $table->string('meta_description', 500)->nullable()->after('meta_title');
                $table->string('meta_keywords', 500)->nullable()->after('meta_description');
            });
        }

        if (Schema::hasTable('categories') && ! Schema::hasColumn('categories', 'meta_title')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('meta_title')->nullable()->after('icon');
                $table->string('meta_description', 500)->nullable()->after('meta_title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'meta_title')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(['meta_title', 'meta_description', 'meta_keywords']);
            });
        }

        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'meta_title')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn(['meta_title', 'meta_description']);
            });
        }
    }
};
