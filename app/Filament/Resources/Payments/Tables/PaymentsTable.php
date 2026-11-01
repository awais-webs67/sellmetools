<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\Actions\PaymentActions;
use App\Filament\Support\Money;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order', 'paymentMethod', 'reviewer']))
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('order.number')
                    ->label('Order')
                    ->searchable()
                    ->weight('bold')
                    ->url(fn (Payment $record): ?string => $record->order
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null),
                TextColumn::make('paymentMethod.name')
                    ->label('Method')
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (Payment $record): string => Money::currency($record->amount, $record->currency))
                    ->description(fn (Payment $record): ?string => strtoupper($record->currency) !== 'PKR' && $record->order
                        ? 'Order total '.Money::pkr($record->order->total)
                        : null)
                    ->sortable(),
                TextColumn::make('transaction_id')
                    ->label('TID')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),
                TextColumn::make('sender_number')
                    ->label('Sender')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => ucfirst($state->value))
                    ->color(fn (PaymentStatus $state): string => $state->color())
                    ->description(fn (Payment $record): ?string => $record->reject_reason),
                TextColumn::make('reviewer.name')
                    ->label('Reviewed by')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $s) => [$s->value => ucfirst($s->value)])->all())
                    ->default(PaymentStatus::Pending->value),
                SelectFilter::make('payment_method_id')
                    ->label('Method')
                    ->relationship('paymentMethod', 'name'),
            ])
            ->recordActions([
                PaymentActions::screenshot()->iconButton()->tooltip('View screenshot'),
                PaymentActions::approve(),
                PaymentActions::reject(),
                Action::make('order')
                    ->label('Order')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Open order')
                    ->url(fn (Payment $record): ?string => $record->order
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null),
            ]);
    }
}
