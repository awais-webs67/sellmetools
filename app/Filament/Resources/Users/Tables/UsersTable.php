<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use RuntimeException;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('phone')
                    ->label('WhatsApp')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-'),
                TextColumn::make('role_badge')
                    ->label('Role')
                    ->state(fn (User $record): string => match (true) {
                        $record->isSuperAdmin() => 'Super Admin',
                        $record->isSalesStaff() => 'Sales Staff',
                        $record->isSeoStaff() => 'SEO Specialist',
                        $record->is_reseller => 'VIP Reseller',
                        default => 'Customer',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Super Admin' => 'danger',
                        'Sales Staff' => 'info',
                        'SEO Specialist' => 'warning',
                        'VIP Reseller' => 'success',
                        default => 'gray',
                    }),
                IconColumn::make('is_reseller')
                    ->label('Reseller')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('reseller_slug')
                    ->label('Reseller Portal URL')
                    ->formatStateUsing(fn (?string $state) => $state ? url('/'.$state) : '-')
                    ->url(fn (User $record) => $record->is_reseller && $record->reseller_slug ? url('/'.$record->reseller_slug) : null, shouldOpenInNewTab: true)
                    ->color('primary')
                    ->searchable(),
                TextColumn::make('reseller_discount_percent')
                    ->label('Global %')
                    ->suffix('%')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('wallet_balance')
                    ->label('Wallet Balance')
                    ->money('PKR')
                    ->badge()
                    ->color(fn ($state) => (float) $state > 0 ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->counts('orders')
                    ->badge()
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->filters([
                TernaryFilter::make('is_reseller')->label('VIP Resellers'),
                TernaryFilter::make('is_active')->label('Active Status'),
                TernaryFilter::make('is_admin')->label('Admins'),
            ])
            ->recordActions([
                Action::make('topup')
                    ->label('Top-Up')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color('success')
                    ->modalHeading(fn (User $record) => "Top-Up Wallet Balance for {$record->name}")
                    ->modalDescription(fn (User $record) => 'Current Balance: Rs '.number_format((float) $record->wallet_balance, 2))
                    ->schema([
                        TextInput::make('amount')
                            ->label('Amount to Add (PKR)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->prefix('Rs'),
                        TextInput::make('note')
                            ->label('Reason / Reference Note')
                            ->placeholder('e.g. Easypaisa deposit received')
                            ->maxLength(190),
                    ])
                    ->action(function (User $record, array $data): void {
                        $amount = (float) $data['amount'];
                        $tx = $record->adjustWallet(
                            $amount,
                            'topup',
                            $data['note'] ?? 'Admin wallet top-up',
                            auth()->id()
                        );

                        Notification::make()
                            ->success()
                            ->title('Wallet Topped Up')
                            ->body('Added Rs '.number_format($amount).' to '.$record->name.'. New Balance: Rs '.number_format((float) $tx->balance_after, 2))
                            ->send();
                    }),

                Action::make('deduct')
                    ->label('Deduct')
                    ->icon(Heroicon::OutlinedMinusCircle)
                    ->color('danger')
                    ->modalHeading(fn (User $record) => "Deduct Wallet Balance from {$record->name}")
                    ->modalDescription(fn (User $record) => 'Current Balance: Rs '.number_format((float) $record->wallet_balance, 2))
                    ->schema([
                        TextInput::make('amount')
                            ->label('Amount to Deduct (PKR)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->prefix('Rs'),
                        TextInput::make('note')
                            ->label('Reason / Note')
                            ->placeholder('e.g. Balance adjustment')
                            ->maxLength(190),
                    ])
                    ->action(function (User $record, array $data): void {
                        try {
                            $amount = (float) $data['amount'];
                            $tx = $record->adjustWallet(
                                -$amount,
                                'deduct',
                                $data['note'] ?? 'Admin wallet deduction',
                                auth()->id()
                            );

                            Notification::make()
                                ->success()
                                ->title('Wallet Balance Deducted')
                                ->body('Deducted Rs '.number_format($amount).' from '.$record->name.'. New Balance: Rs '.number_format((float) $tx->balance_after, 2))
                                ->send();
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Deduction Failed')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
