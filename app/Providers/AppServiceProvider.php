<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Suppliers\SupplierManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
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
            $hash = md5(implode('|', array_map('basename', $files)).'|'.$gitRev);
            $stampFile = storage_path('framework/migration_hash.txt');

            if (is_file($stampFile) && trim((string) file_get_contents($stampFile)) === $hash) {
                return;
            }

            Artisan::call('migrate', ['--force' => true]);
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
}
