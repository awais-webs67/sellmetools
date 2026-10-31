<?php

namespace App\Filament\Resources\IncomingPayments\Pages;

use App\Filament\Resources\IncomingPayments\IncomingPaymentResource;
use App\Services\AutoPaymentVerifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListIncomingPayments extends ListRecords
{
    protected static string $resource = IncomingPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pasteSms')
                ->label('Paste SMS to Auto-Match')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('primary')
                ->modalHeading('Paste Payment SMS (Easypaisa / JazzCash / NayaPay / SadaPay)')
                ->modalDescription('Paste the confirmation SMS you received — the system will extract the TID & Amount and auto-deliver any matching order.')
                ->schema([
                    Textarea::make('sms')
                        ->label('SMS Text')
                        ->placeholder('e.g. You have received Rs 550.00 from 03001234567. Trx ID 12345678901.')
                        ->rows(3)
                        ->required(),
                ])
                ->action(function (array $data, AutoPaymentVerifier $verifier): void {
                    $parsed = $verifier->parseSms($data['sms']);
                    if (! $parsed) {
                        Notification::make()->title('Could not find TID or Amount in that SMS')->danger()->send();

                        return;
                    }

                    $inc = $verifier->recordIncoming($parsed['tid'], $parsed['amount'], $parsed['provider'], 'PKR', $parsed['sender'], $data['sms']);

                    Notification::make()
                        ->title("Saved TID {$inc->transaction_id} (Rs {$inc->amount})")
                        ->body($inc->matched_payment_id ? 'Matched & auto-delivered order!' : 'Waiting for customer to submit this TID.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
