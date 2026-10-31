<?php

namespace App\Filament\Resources\Suppliers\Actions;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierManager;
use App\Services\SupplierSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

final class SupplierActions
{
    public static function sync(): Action
    {
        return Action::make('sync')
            ->label('Sync now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->action(function (Supplier $record): void {
                try {
                    $stats = app(SupplierSyncService::class)->sync($record);
                } catch (Throwable $e) {
                    Notification::make()
                        ->title("Sync failed: {$record->name}")
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("{$record->name} synced")
                    ->body(self::statsSummary($stats))
                    ->success()
                    ->send();
            });
    }

    public static function testConnection(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->action(function (Supplier $record): void {
                try {
                    $balance = app(SupplierManager::class)->driver($record)->balance();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Connection failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Connection OK')
                    ->body('Balance: '.number_format($balance->balance, 2).' '.($balance->currency ?: $record->currency))
                    ->success()
                    ->send();
            });
    }

    public static function syncAll(): Action
    {
        return Action::make('syncAll')
            ->label('Sync all')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalDescription('Pulls products, prices and balances from every active supplier. This may take a while.')
            ->action(function (): void {
                $service = app(SupplierSyncService::class);
                $ok = [];
                $failed = [];

                foreach (Supplier::query()->where('is_active', true)->orderBy('priority')->get() as $supplier) {
                    try {
                        $stats = $service->sync($supplier);
                        $ok[] = "{$supplier->name}: ".self::statsSummary($stats);
                    } catch (Throwable $e) {
                        $failed[] = "{$supplier->name}: {$e->getMessage()}";
                    }
                }

                if ($ok === [] && $failed === []) {
                    Notification::make()->title('No active suppliers to sync')->warning()->send();

                    return;
                }

                Notification::make()
                    ->title($failed === [] ? 'All suppliers synced' : count($failed).' supplier(s) failed to sync')
                    ->body(implode("\n", [...$ok, ...$failed]))
                    ->status($failed === [] ? 'success' : 'warning')
                    ->persistent($failed !== [])
                    ->send();
            });
    }

    /** @param array{products?: int, updated_listings?: int, disabled?: int, enabled?: int} $stats */
    private static function statsSummary(array $stats): string
    {
        return sprintf(
            '%d products · %d listings repriced · %d disabled · %d re-enabled',
            $stats['products'] ?? 0,
            $stats['updated_listings'] ?? 0,
            $stats['disabled'] ?? 0,
            $stats['enabled'] ?? 0,
        );
    }
}
