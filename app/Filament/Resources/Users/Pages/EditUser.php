<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openResellerPortal')
                ->label('Open Reseller Portal')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (User $record): bool => (bool) $record->is_reseller && filled($record->reseller_slug))
                ->url(fn (User $record): string => url('/'.$record->reseller_slug), shouldOpenInNewTab: true),

            Action::make('topupWallet')
                ->label('➕ Top-Up Balance')
                ->color('success')
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount to Add (PKR)')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->prefix('Rs'),
                    TextInput::make('note')
                        ->label('Note')
                        ->placeholder('Easypaisa / Bank transfer deposit'),
                ])
                ->action(function (User $record, array $data): void {
                    $tx = $record->adjustWallet((float) $data['amount'], 'topup', $data['note'] ?? 'Admin top-up', auth()->id());
                    Notification::make()
                        ->success()
                        ->title('Balance Added')
                        ->body('New Wallet Balance: Rs '.number_format((float) $tx->balance_after, 2))
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }
}
