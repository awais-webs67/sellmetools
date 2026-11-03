<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->isSalesStaff());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'email', 'phone'];
    }

    protected static ?array $navBadgeCache = null;

    protected static function navBadgeData(): array
    {
        if (self::$navBadgeCache !== null) {
            return self::$navBadgeCache;
        }

        $fetch = function (): array {
            $row = static::getModel()::query()
                ->whereIn('status', [OrderStatus::PaymentReview, OrderStatus::NeedsAttention])
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as attention', [OrderStatus::NeedsAttention->value])
                ->first();

            return [
                'total' => (int) ($row->total ?? 0),
                'attention' => (int) ($row->attention ?? 0),
            ];
        };

        return self::$navBadgeCache = app()->runningUnitTests()
            ? $fetch()
            : \Illuminate\Support\Facades\Cache::remember('smt_admin_nav_orders', 8, $fetch);
    }

    public static function getNavigationBadge(): ?string
    {
        $total = self::navBadgeData()['total'];

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return self::navBadgeData()['attention'] > 0 ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Payment review + needs attention';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
