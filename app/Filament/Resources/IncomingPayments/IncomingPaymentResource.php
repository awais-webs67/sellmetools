<?php

namespace App\Filament\Resources\IncomingPayments;

use App\Filament\Resources\IncomingPayments\Pages\ListIncomingPayments;
use App\Models\IncomingPayment;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class IncomingPaymentResource extends Resource
{
    protected static ?string $model = IncomingPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Auto-SMS Logs';

    protected static ?string $modelLabel = 'Incoming SMS Payment';

    protected static ?string $pluralModelLabel = 'Auto-SMS Payment Logs';

    protected static ?string $slug = 'incoming-payments';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->isSalesStaff());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('provider')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => strtoupper($state ?? 'SMS')),
                TextColumn::make('transaction_id')
                    ->label('TID')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                TextColumn::make('amount')
                    ->formatStateUsing(fn ($state, IncomingPayment $r): string => $r->currency.' '.number_format((float) $state, 2))
                    ->sortable(),
                TextColumn::make('sender')
                    ->placeholder('-'),
                IconColumn::make('matched_payment_id')
                    ->label('Auto-Matched')
                    ->boolean(fn (IncomingPayment $r): bool => $r->matched_payment_id !== null),
                TextColumn::make('raw_message')
                    ->label('SMS Text')
                    ->limit(55)
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Received')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncomingPayments::route('/'),
        ];
    }
}
