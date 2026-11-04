<?php

namespace App\Providers;

use App\Models\Setting;
use App\Models\User;
use App\Services\ImageOptimizer;
use App\Services\ProductSeoOptimizer;
use App\Services\Suppliers\SupplierManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SupplierManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Catch silently discarded attributes during development.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Never leak Hostinger's temporary preview domain (e.g. aliceblue-*.hostingersite.com)
        // in generated links, receipts, or Telegram Bot webhook URLs.
        $configuredAppUrl = (string) config('app.url', '');
        $requestHost = (! $this->app->runningInConsole() && request()) ? (string) request()->getHost() : '';
        if (str_contains($configuredAppUrl, 'hostingersite.com') || str_contains($requestHost, 'hostingersite.com')) {
            $canonicalUrl = 'https://sellmetools.com';
            config(['app.url' => $canonicalUrl]);
            URL::forceRootUrl($canonicalUrl);
            URL::forceScheme('https');
        }

        Paginator::defaultView('pagination::tailwind');

        $this->autoMigrateDatabaseIfChanged();
        ProductSeoOptimizer::syncCatalogOnce();
        ProductSeoOptimizer::syncCatalogOnce();
    }

    /**
     * Automatically runs pending database migrations ONLY when actual migration files change.
     * Writes the stamp file FIRST and never calls view:clear / cache:clear / ImageOptimizer during web traffic,
     * preventing 500 "Server is busy" errors on shared hosting when refreshing pages.
     */
    protected function autoMigrateDatabaseIfChanged(): void
    {
        if ($this->app->runningUnitTests() || $this->app->runningInConsole()) {
            return;
        }

        $readyFlag = storage_path('framework/schema_v7_ready.flag');
        if (is_file($readyFlag)) {
            return;
        }

        try {
            if ((string) Setting::get('schema_v7_ready', '') === '1') {
                @file_put_contents($readyFlag, '1');

                return;
            }

            Cache::forget('smt_active_categories_v1');

            $files = glob(database_path('migrations/*.php')) ?: [];
            $hash = md5('v7|'.implode('|', array_map('basename', $files)));
            $stampFile = storage_path('framework/migration_hash.txt');

            if (is_file($stampFile) && trim((string) @file_get_contents($stampFile)) === $hash) {
                Setting::set('schema_v7_ready', '1');
                @file_put_contents($readyFlag, '1');

                return;
            }

            // Write stamp file and setting IMMEDIATELY before running migrations so concurrent page refreshes never collide!
            @file_put_contents($stampFile, $hash);
            @file_put_contents($readyFlag, '1');
            Setting::set('schema_v7_ready', '1');

            $this->ensureRequiredSchemaColumns();

            try {
                Artisan::call('migrate', ['--force' => true]);
            } catch (Throwable $e) {
                report($e);
            }

            if (Schema::hasTable('users') && User::query()->count() === 0) {
                Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Guarantees that newly added columns exist even when the production database was
     * imported via phpMyAdmin (.sql) without full Laravel migration history.
     */
    protected function ensureRequiredSchemaColumns(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (! Schema::hasColumn('products', 'delivery_type')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('delivery_type', 40)->default('auto')->after('pricing_mode');
            });
        }

        if (! Schema::hasColumn('products', 'is_free')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('is_free')->default(false)->after('is_featured');
            });
        }

        if (! Schema::hasColumn('products', 'stock_limit')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->unsignedInteger('stock_limit')->nullable()->after('available_stock');
            });
        }

        if (! Schema::hasColumn('products', 'customer_input_label')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('customer_input_label', 160)->nullable()->after('delivery_type');
            });
        }

        if (! Schema::hasColumn('products', 'use_overall_supplier_markup')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('use_overall_supplier_markup')->default(true)->after('markup_value');
            });
        }

        if (Schema::hasTable('supplier_orders')) {
            try {
                Schema::table('supplier_orders', function (Blueprint $table): void {
                    $table->unsignedBigInteger('order_item_id')->nullable()->change();
                });
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'customer_input')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->text('customer_input')->nullable()->after('notes');
            });
        }

        if (Schema::hasTable('product_sources')) {
            if (! Schema::hasColumn('product_sources', 'pricing_mode')) {
                Schema::table('product_sources', function (Blueprint $table): void {
                    $table->string('pricing_mode', 20)->nullable()->after('is_active');
                });
            }
            if (! Schema::hasColumn('product_sources', 'markup_value')) {
                Schema::table('product_sources', function (Blueprint $table): void {
                    $table->decimal('markup_value', 12, 2)->nullable()->after('pricing_mode');
                });
            }
        }
    }
}
