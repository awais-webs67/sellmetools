<?php

namespace App\Filament\Resources\PaymentMethods\Tables;

use App\Models\PaymentMethod;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class PaymentMethodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                ImageColumn::make('logo')
                    ->disk('public')
                    ->imageSize(32)
                    ->label(''),
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (PaymentMethod $record): string => $record->code),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'auto' ? 'info' : 'gray'),
                TextColumn::make('currency')
                    ->badge(),
                TextColumn::make('account_title')
                    ->description(fn (PaymentMethod $record): ?string => $record->account_number)
                    ->placeholder('—'),
                IconColumn::make('requires_sender_number')
                    ->label('Sender no.')
                    ->boolean(),
                ToggleColumn::make('is_active')
                    ->label('Active'),
                TextColumn::make('sort')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
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
