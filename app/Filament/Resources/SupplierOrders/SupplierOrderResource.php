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
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

class SupplierOrderResource extends Resource
{
    protected static ?string $model = SupplierOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Suppliers';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Vendor Orders';

    protected static ?string $modelLabel = 'Vendor Order';

    protected static ?string $pluralModelLabel = 'Vendor Orders';

    protected static ?string $slug = 'vendor-orders';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->isSalesStaff());
    }

    public static function getNavigationBadge(): ?string
    {
        $failed = SupplierOrder::query()
            ->whereIn('status', [SupplierOrderStatus::Failed, SupplierOrderStatus::Unknown])
            ->count();

        return $failed > 0 ? (string) $failed : null;
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
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('orderItem.order.number')
                    ->label('Customer Order')
                    ->searchable()
                    ->weight('bold')
                    ->url(fn (SupplierOrder $r): ?string => $r->orderItem?->order
                        ? OrderResource::getUrl('view', ['record' => $r->orderItem->order])
                        : null)
                    ->placeholder('Direct Test'),
                TextColumn::make('supplier.name')
                    ->label('Vendor / Supplier')
                    ->sortable(),
                TextColumn::make('supplierProduct.name')
                    ->label('Vendor Product')
                    ->searchable()
                    ->wrap()
                    ->description(fn (SupplierOrder $r): string => 'Qty: '.$r->quantity.' · Req ID: '.$r->request_id),
                TextColumn::make('external_order_id')
                    ->label('Vendor Order ID')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-'),
                TextColumn::make('cost')
                    ->label('Cost')
                    ->formatStateUsing(fn ($state, SupplierOrder $r): string => $state !== null
                        ? Money::currency($state, $r->supplier?->currency ?? 'USDT')
                        : '-')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (SupplierOrderStatus $state): string => $state->color()),
                TextColumn::make('error')
                    ->label('Error / Info')
                    ->limit(45)
                    ->color('danger')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Time')
                    ->since()
                    ->sortable(),
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
                            ->visible(fn (): bool => $r->orderItem && $r->orderItem->deliveries()->where('supplier_order_id', $r->id)->exists())
                            ->schema([
                                TextEntry::make('delivered_items')
                                    ->label('')
                                    ->state(function () use ($r): HtmlString {
                                        $lines = $r->orderItem->deliveries()->where('supplier_order_id', $r->id)->get()->map(fn ($d) => e($d->content))->implode('<br>');

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
                    ->label('Retry Order')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (SupplierOrder $r): bool => $r->status === SupplierOrderStatus::Failed && $r->orderItem?->order !== null)
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
                $reqId = 'ADMIN-'.strtoupper(uniqid());

                try {
                    $res = app(SupplierManager::class)->driver($supplier)->placeOrder($sp->external_id, $qty, $reqId);
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Vendor order failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                if ($res->balanceAfter !== null) {
                    app(SupplierSyncService::class)->recordBalance($supplier, $res->balanceAfter);
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
                    ->title('Vendor order successful! ('.count($res->items).' item(s))')
                    ->body(implode("\n", $res->items))
                    ->persistent()
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierOrders::route('/'),
        ];
    }
}
