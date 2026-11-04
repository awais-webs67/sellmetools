<?php

namespace App\Filament\Resources\SupplierOrders;

use App\Enums\OrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SupplierOrders\Pages\ListSupplierOrders;
use App\Filament\Support\Listings;
use App\Filament\Support\Money;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Services\FulfillmentService;
use App\Services\Suppliers\SupplierManager;
use App\Services\SupplierSyncService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

class SupplierOrderResource extends Resource
{
    protected static ?string $model = SupplierOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Suppliers';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Direct & Vendor Orders';

    protected static ?string $modelLabel = 'Vendor Order';

    protected static ?string $pluralModelLabel = 'Direct & Vendor Orders';

    protected static ?string $slug = 'vendor-orders';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->isSalesStaff());
    }

    public static function getNavigationBadge(): ?string
    {
        $fetch = fn (): int => (int) SupplierOrder::query()
            ->whereIn('status', [SupplierOrderStatus::Failed, SupplierOrderStatus::Unknown])
            ->whereHas('orderItem.order', function (Builder $query): void {
                $query->whereIn('status', [OrderStatus::Processing, OrderStatus::NeedsAttention]);
            })
            ->count();

        $failed = app()->runningUnitTests()
            ? $fetch()
            : (int) Cache::remember('smt_admin_nav_vendor_orders_v2', 12, $fetch);

        return $failed > 0 ? (string) $failed : null;
    }

    /**
     * Returns true if this supplier attempt failed/timed out, BUT the customer's order
     * has ALREADY been delivered (via manual delivery, WhatsApp, or backup supplier) or cancelled/refunded.
     */
    public static function isOrderAlreadyResolved(SupplierOrder $r): bool
    {
        if ($r->status === SupplierOrderStatus::Success || $r->order_item_id === null) {
            return false;
        }

        $order = $r->orderItem?->order;
        if (! $order) {
            return true;
        }

        if (in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Refunded, OrderStatus::Expired], true)) {
            return true;
        }

        return $r->orderItem->remainingQty() <= 0;
    }

    /**
     * Returns true ONLY when this supplier order failed/timed out AND the customer order is STILL unfulfilled.
     */
    public static function isStillUnfulfilled(SupplierOrder $r): bool
    {
        if (! in_array($r->status, [SupplierOrderStatus::Failed, SupplierOrderStatus::Unknown], true)) {
            return false;
        }

        if ($r->order_item_id === null) {
            return false;
        }

        return ! self::isOrderAlreadyResolved($r);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'supplierProduct', 'orderItem.order']))
            ->defaultSort('created_at', 'desc')
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('order_type')
                    ->label('Order Type / #')
                    ->state(fn (SupplierOrder $r): string => $r->orderItem?->order?->number ?: '⚡ Direct Buy')
                    ->badge(fn (SupplierOrder $r): bool => $r->order_item_id === null)
                    ->color(fn (SupplierOrder $r): string => $r->order_item_id === null ? 'warning' : 'primary')
                    ->weight('bold')
                    ->url(fn (SupplierOrder $r): ?string => $r->orderItem?->order
                        ? OrderResource::getUrl('view', ['record' => $r->orderItem->order])
                        : null)
                    ->description(fn (SupplierOrder $r): string => $r->created_at?->diffForHumans(short: true) ?? ''),
                TextColumn::make('supplier.name')
                    ->label('Vendor')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('supplierProduct.name')
                    ->label('Vendor Product')
                    ->searchable()
                    ->limit(32)
                    ->tooltip(fn (SupplierOrder $r): string => (string) ($r->supplierProduct?->name ?? ''))
                    ->description(fn (SupplierOrder $r): string => 'Qty: '.$r->quantity.($r->external_order_id ? ' · ID: '.$r->external_order_id : '')),
                TextColumn::make('cost')
                    ->label('Cost')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state, SupplierOrder $r): string => $state !== null
                        ? Money::currency($state, $r->supplier?->currency ?? 'USDT')
                        : '-')
                    ->description(fn ($state, SupplierOrder $r): ?string => ($state !== null && $r->supplier)
                        ? Money::pkr(round($r->supplier->toPkr((float) $state), 0))
                        : null)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(function (SupplierOrderStatus $state, SupplierOrder $r): string {
                        if (self::isOrderAlreadyResolved($r)) {
                            $orderStatus = $r->orderItem?->order?->status;

                            return $orderStatus === OrderStatus::Completed ? 'Resolved (Delivered)' : 'Order Closed';
                        }

                        return $state->name;
                    })
                    ->color(function (SupplierOrderStatus $state, SupplierOrder $r): string {
                        if (self::isOrderAlreadyResolved($r)) {
                            return 'gray';
                        }

                        return $state->color();
                    }),
                TextColumn::make('error')
                    ->label('Error / Info')
                    ->limit(30)
                    ->tooltip(fn (SupplierOrder $r): ?string => $r->error ?: null)
                    ->color(fn (SupplierOrder $r): string => self::isStillUnfulfilled($r) ? 'danger' : 'gray')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Time')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Vendor')
                    ->relationship('supplier', 'name')
                    ->preload(),
                SelectFilter::make('status')
                    ->options([
                        SupplierOrderStatus::Success->value => 'Success',
                        SupplierOrderStatus::Failed->value => 'Failed',
                        SupplierOrderStatus::Unknown->value => 'Unknown (Timeout)',
                        SupplierOrderStatus::Pending->value => 'Pending',
                    ]),
            ])
            ->recordActions([
                Action::make('viewResponse')
                    ->label('Details')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->button()
                    ->size('xs')
                    ->modalHeading(fn (SupplierOrder $r): string => 'Vendor Order #'.$r->id.' ('.$r->request_id.')')
                    ->modalSubmitAction(false)
                    ->schema(fn (SupplierOrder $r): array => [
                        Section::make('Summary')
                            ->columns(['default' => 1, 'sm' => 2])
                            ->schema([
                                TextEntry::make('supplier.name')->label('Vendor'),
                                TextEntry::make('external_order_id')->label('Vendor Order ID')->placeholder('-'),
                                TextEntry::make('request_id')->label('Request ID'),
                                TextEntry::make('status')->badge()->color(fn (SupplierOrderStatus $state): string => $state->color()),
                                TextEntry::make('error')->label('Error Message')->color('danger')->columnSpanFull()->visible(fn (): bool => filled($r->error)),
                            ]),
                        Section::make('Delivered Credentials / Items')
                            ->visible(fn (): bool => self::extractDeliveredLines($r) !== [])
                            ->schema([
                                TextEntry::make('delivered_items')
                                    ->label('')
                                    ->state(function () use ($r): HtmlString {
                                        $lines = collect(self::extractDeliveredLines($r))->map(fn (string $l) => e($l))->implode("\n---\n");

                                        return new HtmlString('<pre class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs font-mono text-slate-800 whitespace-pre-wrap break-all overflow-x-auto">'.$lines.'</pre>');
                                    }),
                            ]),
                        Section::make('Raw API Response')
                            ->schema([
                                TextEntry::make('raw_json')
                                    ->label('')
                                    ->state(fn (): HtmlString => new HtmlString(
                                        '<pre class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs font-mono text-slate-800 whitespace-pre-wrap break-all overflow-x-auto">'
                                        .e(json_encode($r->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                                        .'</pre>'
                                    )),
                            ]),
                    ]),
                Action::make('retryOrder')
                    ->label('Retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->button()
                    ->size('xs')
                    ->requiresConfirmation()
                    ->visible(fn (SupplierOrder $r): bool => self::isStillUnfulfilled($r))
                    ->action(function (SupplierOrder $r): void {
                        $order = $r->orderItem->order;
                        if ($order->status === OrderStatus::NeedsAttention) {
                            $order->update(['status' => OrderStatus::Processing]);
                        }
                        $order = app(FulfillmentService::class)->fulfill($order);

                        Notification::make()
                            ->title('Fulfillment retried: Order is now '.$order->status->label())
                            ->color($order->status === OrderStatus::Completed ? 'success' : 'warning')
                            ->send();
                    }),
            ]);
    }

    /** Direct test / wholesale purchase modal action for ListSupplierOrders header. */
    public static function directBuyAction(): Action
    {
        return Action::make('directVendorOrder')
            ->label('Buy Directly from Vendor API')
            ->icon(Heroicon::OutlinedShoppingBag)
            ->color('primary')
            ->modalHeading('Place Direct Order on Vendor API')
            ->modalDescription('Test a supplier product or buy stock directly from your vendor wallet.')
            ->modalSubmitActionLabel('Place Vendor Order')
            ->schema([
                Select::make('supplier_id')
                    ->label('Vendor / Supplier')
                    ->options(fn (): array => Supplier::query()->where('is_active', true)->pluck('name', 'id')->all())
                    ->required()
                    ->live(),
                Select::make('supplier_product_id')
                    ->label('Vendor Product')
                    ->options(fn (Get $get): array => $get('supplier_id')
                        ? SupplierProduct::query()
                            ->where('supplier_id', $get('supplier_id'))
                            ->where('is_available', true)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (SupplierProduct $sp) => [
                                $sp->id => "{$sp->name}: {$sp->cost} {$sp->supplier->currency} (Stock: ".($sp->unlimited_stock ? '∞' : (int) $sp->stock).')',
                            ])
                            ->all()
                        : [])
                    ->searchable()
                    ->required(),
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->rule('integer')
                    ->rule('min:1')
                    ->default(1)
                    ->required(),
                Select::make('save_to_product_id')
                    ->label('Add delivered items to Own Stock of (Optional)')
                    ->placeholder('Just show me the credentials on screen')
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->action(function (array $data): void {
                $supplier = Supplier::findOrFail($data['supplier_id']);
                $sp = SupplierProduct::findOrFail($data['supplier_product_id']);
                $qty = max(1, (int) $data['quantity']);
                $reqId = 'DIRECT-'.strtoupper(uniqid());

                $attempt = SupplierOrder::create([
                    'supplier_id' => $supplier->id,
                    'order_item_id' => null,
                    'supplier_product_id' => $sp->id,
                    'request_id' => $reqId,
                    'quantity' => $qty,
                    'status' => SupplierOrderStatus::Pending,
                ]);

                try {
                    $res = app(SupplierManager::class)->driver($supplier)->placeOrder($sp->external_id, $qty, $reqId);
                } catch (Throwable $e) {
                    $errLower = strtolower($e->getMessage());
                    $isBridgeable = str_contains($errLower, 'cooldown')
                        || str_contains($errLower, 'timed out')
                        || str_contains($errLower, 'connection error')
                        || str_contains($errLower, 'failed to connect');

                    if ($isBridgeable) {
                        $attempt->update([
                            'status' => SupplierOrderStatus::Pending,
                            'error' => 'Relaying via Admin Browser Bridge...',
                            'response' => ['save_to_product_id' => $data['save_to_product_id'] ?? null],
                        ]);

                        Notification::make()
                            ->title("Connecting to {$supplier->name} via Browser Bridge...")
                            ->body('Your browser is completing the direct purchase now. The page will refresh in 2 seconds with your credentials!')
                            ->info()
                            ->send();

                        return;
                    }

                    $attempt->update([
                        'status' => SupplierOrderStatus::Failed,
                        'error' => $e->getMessage(),
                    ]);

                    Notification::make()
                        ->title('Vendor order failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $cost = $res->total ?? ((float) $sp->cost * max(1, count($res->items)));
                $rawWithItems = is_array($res->raw) ? $res->raw : [];
                $rawWithItems['delivered_items'] = $res->items;

                $attempt->update([
                    'status' => SupplierOrderStatus::Success,
                    'cost' => $cost,
                    'external_order_id' => $res->externalOrderId,
                    'response' => $rawWithItems,
                ]);

                if ($res->balanceAfter !== null) {
                    app(SupplierSyncService::class)->recordBalance($supplier, $res->balanceAfter);
                }

                if ($sp->stock !== null && ! $sp->unlimited_stock) {
                    $sp->decrement('stock', min($sp->stock, count($res->items)));
                }

                if (! empty($data['save_to_product_id'])) {
                    $product = Product::find($data['save_to_product_id']);
                    if ($product) {
                        foreach ($res->items as $line) {
                            $product->stockItems()->create(['content' => $line]);
                        }
                        Listings::refresh($product);
                    }
                }

                Notification::make()
                    ->title('Direct Vendor Order Saved! ('.count($res->items).' item(s))')
                    ->body(implode("\n", $res->items))
                    ->persistent()
                    ->success()
                    ->send();
            });
    }

    /**
     * Extracts delivered credential lines for both Customer Orders and Direct Admin Buys.
     *
     * @return string[]
     */
    public static function extractDeliveredLines(SupplierOrder $r): array
    {
        if ($r->orderItem) {
            $fromDeliveries = $r->orderItem->deliveries()
                ->where('supplier_order_id', $r->id)
                ->pluck('content')
                ->filter(fn ($v): bool => trim((string) $v) !== '')
                ->values()
                ->all();
            if ($fromDeliveries !== []) {
                return $fromDeliveries;
            }
        }

        $resp = is_array($r->response) ? $r->response : [];
        $candidates = $resp['delivered_items']
            ?? data_get($resp, 'order.items')
            ?? data_get($resp, 'data.items')
            ?? data_get($resp, 'items')
            ?? [];

        if (! is_array($candidates)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $candidates),
            fn (string $l): bool => $l !== ''
        ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierOrders::route('/'),
        ];
    }
}
