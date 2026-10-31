<?php

use App\Models\Supplier;
use App\Services\OrderService;
use App\Services\SupplierSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('suppliers:sync {supplier? : Supplier ID (all active when omitted)}', function (SupplierSyncService $sync) {
    $query = Supplier::query()->where('is_active', true);
    if ($id = $this->argument('supplier')) {
        $query->whereKey($id);
    }

    foreach ($query->get() as $supplier) {
        try {
            $stats = $sync->sync($supplier);
            $this->info("{$supplier->name}: {$stats['products']} products, {$stats['updated_listings']} listings repriced, {$stats['disabled']} disabled, {$stats['enabled']} re-enabled.");
        } catch (Throwable $e) {
            $this->error("{$supplier->name}: {$e->getMessage()}");
            report($e);
        }
    }
})->purpose('Sync products, prices, stock and balance from supplier APIs');

Artisan::command('orders:expire', function (OrderService $orders) {
    $count = $orders->expireStale();
    $this->info("Expired {$count} unpaid order(s).");
})->purpose('Release stock held by unpaid orders past their deadline');

// One cron entry on the server runs everything:  * * * * * php /path/artisan schedule:run
Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();
Schedule::command('suppliers:sync')->everyTenSeconds()->withoutOverlapping(1)->runInBackground();
