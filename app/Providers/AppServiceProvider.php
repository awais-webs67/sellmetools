<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ImageOptimizer;
use App\Services\Suppliers\SupplierManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
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

        Paginator::defaultView('pagination::tailwind');

        $this->autoMigrateDatabaseIfChanged();

        if (! $this->app->runningUnitTests() && ! $this->app->runningInConsole()) {
            try {
                app(\App\Services\DatabaseBackupService::class)->checkAndRunScheduledBackup();
            } catch (Throwable) {
                // Ignore if DB not ready
            }
        }
    }

    /**
     * Automatically runs pending database migrations (and initial seed if empty) whenever
     * new migration files are deployed via Git, no SSH or phpMyAdmin import needed.
     */
    protected function autoMigrateDatabaseIfChanged(): void
    {
        if ($this->app->runningUnitTests() || $this->app->runningInConsole()) {
            return;
        }

        try {
            $files = glob(database_path('migrations/*.php')) ?: [];
            $gitHeadFile = base_path('.git/refs/heads/main');
            $gitRev = is_file($gitHeadFile) ? trim((string) @file_get_contents($gitHeadFile)) : '';
            $hash = md5('v3|'.implode('|', array_map('basename', $files)).'|'.$gitRev);
            $stampFile = storage_path('framework/migration_hash.txt');

            if (is_file($stampFile) && trim((string) file_get_contents($stampFile)) === $hash) {
                return;
            }

            $this->ensureRequiredSchemaColumns();

            try {
                Artisan::call('migrate', ['--force' => true]);
            } catch (Throwable $e) {
                // If database was imported via .sql and an older migration table already exists,
                // ensureRequiredSchemaColumns() above guarantees all new columns exist.
                report($e);
            }

            try {
                app(ImageOptimizer::class)->optimizeAllExisting();
            } catch (Throwable $e) {
                report($e);
            }

            Artisan::call('view:clear');
            Artisan::call('cache:clear');

            if (Schema::hasTable('users') && User::query()->count() === 0) {
                Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
            }

            @file_put_contents($stampFile, $hash);
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

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'customer_input')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->text('customer_input')->nullable()->after('notes');
            });
        }
    }
}
