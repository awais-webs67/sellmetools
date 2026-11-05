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
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
     * Returns true ONLY when this supplier order failed/timed out AND the order is STILL unfulfilled.
     */
    public static function isStillUnfulfilled(SupplierOrder $r): bool
    {
        if (! in_array($r->status, [SupplierOrderStatus::Failed, SupplierOrderStatus::Unknown], true)) {
            return false;
        }

        if ($r->order_item_id === null) {
            return true;
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
                    ->state(function (SupplierOrder $r): string {
                        if ($r->orderItem?->order?->number) {
                            return $r->orderItem->order->number;
                        }
                        $resp = is_array($r->response) ? $r->response : [];

                        return ! empty($resp['for_customer']) ? 'Direct Customer' : 'Direct Buy';
                    })
                    ->badge(fn (SupplierOrder $r): bool => $r->order_item_id === null)
                    ->color(function (SupplierOrder $r): string {
                        if ($r->order_item_id !== null) {
                            return 'primary';
                        }
                        $resp = is_array($r->response) ? $r->response : [];

                        return ! empty($resp['for_customer']) ? 'success' : 'warning';
                    })
                    ->weight('bold')
                    ->url(fn (SupplierOrder $r): ?string => $r->orderItem?->order
                        ? OrderResource::getUrl('view', ['record' => $r->orderItem->order])
                        : null)
                    ->description(function (SupplierOrder $r): string {
                        $time = $r->created_at?->diffForHumans(short: true) ?? '';
                        $resp = is_array($r->response) ? $r->response : [];
                        $note = trim((string) ($resp['customer_note'] ?? ''));

                        return $note !== '' ? "{$note} · {$time}" : $time;
                    }),
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
                    ->label('Cost & Profit')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state, SupplierOrder $r): string => $state !== null
                        ? Money::currency($state, $r->supplier?->currency ?? 'USDT')
                        : '-')
                    ->description(function ($state, SupplierOrder $r): ?string {
                        if ($state === null || ! $r->supplier) {
                            return null;
                        }
                        $costPkr = round($r->supplier->toPkr((float) $state), 0);
                        $resp = is_array($r->response) ? $r->response : [];
                        if ($r->order_item_id === null && ! empty($resp['for_customer'])) {
                            $sellPkr = round((float) ($resp['customer_sell_price_pkr'] ?? 0), 0);
                            $profitPkr = $sellPkr - $costPkr;
                            $sign = $profitPkr >= 0 ? '+' : '';

                            return 'Cost: '.Money::pkr($costPkr).' · Sold: '.Money::pkr($sellPkr)." ({$sign}".Money::pkr($profitPkr).')';
                        }

                        return Money::pkr($costPkr);
                    })
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
                        if ($r->order_item_id === null) {
                            $r->update([
                                'status' => SupplierOrderStatus::Pending,
                                'error' => 'Dispatching via live supplier bridge...',
                                'created_at' => now(),
                            ]);

                            Notification::make()
                                ->title('Direct Vendor Order Queued for Instant Delivery')
                                ->body('Delivering via live supplier bridge now (1-2 seconds)...')
                                ->info()
                                ->send();

                            return;
                        }

                        $order = $r->orderItem->order;
                        if ($order->status === OrderStatus::NeedsAttention) {
                            $order->update(['status' => OrderStatus::Processing]);
                        }

                        if ($r->supplierProduct) {
                            $order = app(FulfillmentService::class)->fulfillFromSpecificSupplier($order, $r->orderItem, $r->supplierProduct);
                        } else {
                            $order = app(FulfillmentService::class)->fulfill($order);
                        }

                        $isBridge = $order->status === OrderStatus::Processing && str_contains((string) $order->admin_note, 'bridge');
                        Notification::make()
                            ->title($isBridge ? 'Delivering via Live Supplier Bridge (1-2s)...' : 'Fulfillment retried: Order is now '.$order->status->label())
                            ->color($order->status === OrderStatus::Completed ? 'success' : ($isBridge ? 'info' : 'warning'))
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
            ->modalDescription('Buy from supplier at supplier cost. Enable "Buying for a Customer" to automatically record your selling price, supplier cost, and net profit in Sales & Profit Reports.')
            ->modalSubmitActionLabel('Buy at Supplier Cost & Save to Ledger')
            ->fillForm([
                'for_customer' => true,
                'quantity' => 1,
                'customer_sell_price_pkr' => 0,
                'customer_note' => '',
            ])
            ->schema([
                Toggle::make('for_customer')
                    ->label('Buying for a Customer? (Record Sale Revenue, Cost & Profit in Ledger)')
                    ->helperText('When ON: buys from supplier at supplier cost and logs your customer selling price & net profit in Sales & Profit Reports.')
                    ->default(true)
                    ->live(),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
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
                                    $sp->id => "{$sp->name}: {$sp->cost} {$sp->supplier->currency} ≈ Rs ".number_format($sp->costPkr(), 0).' (Stock: '.($sp->unlimited_stock ? '∞' : (int) $sp->stock).')',
                                ])
                                ->all()
                            : [])
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                            if (! $state) {
                                return;
                            }
                            $sp = SupplierProduct::with('supplier')->find($state);
                            if (! $sp) {
                                return;
                            }
                            $qty = max(1, (int) ($get('quantity') ?: 1));
                            $linkedProduct = $sp->products()->first();
                            $unitCostPkr = round($sp->costPkr(), 0);
                            $unitSellPkr = $linkedProduct
                                ? round($linkedProduct->calculateSellPriceForSource($sp), 0)
                                : max($unitCostPkr, round($unitCostPkr * 1.25, 0));
                            $set('customer_sell_price_pkr', round($unitSellPkr * $qty, 0));
                        }),
                    TextInput::make('quantity')
                        ->label('Quantity')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required()
                        ->live(debounce: 200)
                        ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                            $qty = max(1, (int) $state);
                            $spId = $get('supplier_product_id');
                            if (! $spId) {
                                return;
                            }
                            $sp = SupplierProduct::with('supplier')->find($spId);
                            if (! $sp) {
                                return;
                            }
                            $linkedProduct = $sp->products()->first();
                            $unitCostPkr = round($sp->costPkr(), 0);
                            $unitSellPkr = $linkedProduct
                                ? round($linkedProduct->calculateSellPriceForSource($sp), 0)
                                : max($unitCostPkr, round($unitCostPkr * 1.25, 0));
                            $set('customer_sell_price_pkr', round($unitSellPkr * $qty, 0));
                        }),
                    TextInput::make('customer_sell_price_pkr')
                        ->label('Total Customer Selling Price (PKR)')
                        ->prefix('Rs')
                        ->numeric()
                        ->minValue(0)
                        ->live(debounce: 200)
                        ->visible(fn (Get $get): bool => (bool) $get('for_customer'))
                        ->required(fn (Get $get): bool => (bool) $get('for_customer')),
                    TextInput::make('customer_note')
                        ->label('Customer Name / Reference (Optional)')
                        ->placeholder('e.g. WhatsApp Customer / Ali')
                        ->visible(fn (Get $get): bool => (bool) $get('for_customer'))
                        ->columnSpanFull(),
                ]),
                TextEntry::make('ledger_profit_preview')
                    ->label('Live Cost & Profit Ledger Breakdown')
                    ->visible(fn (Get $get): bool => filled($get('supplier_product_id')))
                    ->state(function (Get $get): string {
                        $spId = $get('supplier_product_id');
                        $sp = $spId ? SupplierProduct::with('supplier')->find($spId) : null;
                        if (! $sp) {
                            return 'Select a vendor product above to preview cost and profit.';
                        }
                        $qty = max(1, (int) ($get('quantity') ?: 1));
                        $costPkr = round($sp->costPkr() * $qty, 0);
                        $costRaw = Money::currency((float) $sp->cost * $qty, $sp->supplier?->currency ?? 'USDT');

                        if (! $get('for_customer')) {
                            return 'Supplier Buy Cost: Rs '.number_format($costPkr, 0)." ({$costRaw}) · Internal Stock / Admin Purchase";
                        }

                        $sellPkr = round((float) ($get('customer_sell_price_pkr') ?: 0), 0);
                        $profitPkr = $sellPkr - $costPkr;
                        $sign = $profitPkr >= 0 ? '+' : '';

                        return sprintf(
                            'Supplier Cost: Rs %s (%s)  |  Customer Price: Rs %s  |  Your Net Profit: %sRs %s',
                            number_format($costPkr, 0),
                            $costRaw,
                            number_format($sellPkr, 0),
                            $sign,
                            number_format($profitPkr, 0),
                        );
                    }),
                Select::make('save_to_product_id')
                    ->label('Add delivered items to Own Stock of (Optional)')
                    ->placeholder('Just show me the credentials on screen')
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (Get $get): bool => ! (bool) $get('for_customer')),
            ])
            ->action(function (array $data): void {
                $supplier = Supplier::findOrFail($data['supplier_id']);
                $sp = SupplierProduct::findOrFail($data['supplier_product_id']);
                $qty = max(1, (int) $data['quantity']);
                $forCustomer = (bool) ($data['for_customer'] ?? false);
                $customerSellPricePkr = $forCustomer ? round((float) ($data['customer_sell_price_pkr'] ?? 0), 2) : 0.0;
                $customerNote = $forCustomer ? trim((string) ($data['customer_note'] ?? '')) : '';
                $reqId = 'DIRECT-'.strtoupper(uniqid());

                $metaPayload = [
                    'save_to_product_id' => $data['save_to_product_id'] ?? null,
                    'for_customer' => $forCustomer,
                    'customer_sell_price_pkr' => $customerSellPricePkr,
                    'customer_note' => $customerNote,
                ];

                $attempt = SupplierOrder::create([
                    'supplier_id' => $supplier->id,
                    'order_item_id' => null,
                    'supplier_product_id' => $sp->id,
                    'request_id' => $reqId,
                    'quantity' => $qty,
                    'status' => SupplierOrderStatus::Pending,
                    'response' => $metaPayload,
                ]);

                try {
                    $res = app(SupplierManager::class)->driver($supplier)->placeOrder($sp->external_id, $qty, $reqId);
                } catch (Throwable $e) {
                    if (FulfillmentService::isBridgeHandoffError($e->getMessage())) {
                        $attempt->update([
                            'status' => SupplierOrderStatus::Pending,
                            'error' => 'Dispatching via live supplier bridge...',
                            'response' => $metaPayload,
                        ]);

                        Notification::make()
                            ->title("Delivering from {$supplier->name}...")
                            ->body('Completing direct purchase via live supplier bridge now (1-2 seconds)...')
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
                $rawWithItems = array_merge(is_array($res->raw) ? $res->raw : [], $metaPayload);
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

                $costPkr = round($supplier->toPkr((float) $cost), 0);
                $profitLine = $forCustomer
                    ? sprintf("\nSold: Rs %s · Cost: Rs %s · Profit: +Rs %s (Added to Ledger)", number_format($customerSellPricePkr, 0), number_format($costPkr, 0), number_format($customerSellPricePkr - $costPkr, 0))
                    : '';

                Notification::make()
                    ->title('Direct Vendor Order Delivered! ('.count($res->items).' item(s))')
                    ->body(implode("\n", $res->items).$profitLine)
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
