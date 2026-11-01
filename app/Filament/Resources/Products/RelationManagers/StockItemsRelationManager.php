<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\StockStatus;
use App\Filament\Support\Listings;
use App\Models\Product;
use App\Models\StockItem;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Own inventory (.TXT files, multi-line accounts, keys, codes) delivered before falling back to suppliers. */
class StockItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockItems';

    protected static ?string $title = 'Own Stock Vault (.TXT & Credentials)';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (StockItem $record): string => "Stock item #{$record->id}")
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('preview')
                    ->label('Credential Preview (1 Row = 1 PC)')
                    ->state(fn (StockItem $record): string => Str::limit(preg_replace('/\s+/', ' ↵ ', trim((string) $record->content)) ?: '—', 55))
                    ->fontFamily('mono')
                    ->color('gray'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (StockStatus $state): string => match ($state) {
                        StockStatus::Available => 'Available (Ready to Sell)',
                        StockStatus::Reserved => 'Reserved (In Checkout)',
                        StockStatus::Sold => 'Sold (Delivered — Locked)',
                    })
                    ->color(fn (StockStatus $state): string => match ($state) {
                        StockStatus::Available => 'success',
                        StockStatus::Reserved => 'warning',
                        StockStatus::Sold => 'gray',
                    }),
                TextColumn::make('orderItem.order.number')
                    ->label('Sold To Order')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(StockStatus::cases())->mapWithKeys(fn (StockStatus $s) => [$s->value => ucfirst($s->value)])->all())
                    ->default(StockStatus::Available->value),
            ])
            ->headerActions([
                Action::make('bulkAdd')
                    ->label('Upload .TXT File / Add Stock')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->modalWidth('2xl')
                    ->modalHeading('Upload .TXT Stock File or Paste Credentials')
                    ->modalDescription('Each extracted unit equals 1 PC. When a customer buys N pcs, the system extracts N units and marks them Sold so they are NEVER sold again.')
                    ->schema([
                        Select::make('separator_mode')
                            ->label('How Are Single Units (1 PC) Separated?')
                            ->options([
                                'auto' => '✨ Smart Auto-Detect (Splits by --- or === if present, otherwise 1 Line = 1 PC)',
                                'separator' => '✂️ Multi-Line Credentials Separated by --- or === (Best for multi-line accounts)',
                                'blank_line' => '📄 Multi-Line Credentials Separated by a Blank Empty Line',
                                'line' => '📏 Single-Line Credentials (1 Line = 1 PC, e.g. email:password)',
                                'custom' => '🔧 Custom Separator (e.g. ### or ***)',
                            ])
                            ->default('auto')
                            ->live()
                            ->required(),
                        TextInput::make('custom_separator')
                            ->label('Custom Separator Text')
                            ->default('---')
                            ->placeholder('e.g. --- or ###')
                            ->visible(fn (Get $get): bool => $get('separator_mode') === 'custom')
                            ->required(fn (Get $get): bool => $get('separator_mode') === 'custom'),
                        FileUpload::make('txt_file')
                            ->label('Option 1: Upload .TXT Stock File')
                            ->disk('local')
                            ->directory('tmp-stock')
                            ->acceptedFileTypes(['text/plain', '.txt'])
                            ->maxSize(5120)
                            ->helperText('Upload a .txt file containing your accounts/keys. You can separate multi-line credentials with --- on its own line.'),
                        Textarea::make('items')
                            ->label('Option 2: Or Paste Credentials Directly')
                            ->helperText('For multi-line credentials (like Email + Password + Pin), put --- on a line between Account A and Account B.')
                            ->placeholder("Email: accountA@gmail.com\nPassword: pass123\nPin: 1111\n---\nEmail: accountB@gmail.com\nPassword: pass456\nPin: 2222")
                            ->rows(10),
                        Toggle::make('skip_duplicates')
                            ->label('Skip duplicate credentials that are already in Available stock')
                            ->default(true),
                    ])
                    ->action(function (array $data): void {
                        $combinedText = '';

                        if (! empty($data['txt_file'])) {
                            $filePath = (string) $data['txt_file'];
                            if (Storage::disk('local')->exists($filePath)) {
                                $combinedText .= (string) Storage::disk('local')->get($filePath)."\n";
                                Storage::disk('local')->delete($filePath);
                            }
                        }

                        if (! empty($data['items'])) {
                            $combinedText .= "\n".(string) $data['items'];
                        }

                        if (trim($combinedText) === '') {
                            Notification::make()
                                ->title('Please upload a .TXT file or paste credentials.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $added = $this->owner()->importStockItems(
                            $combinedText,
                            (string) ($data['separator_mode'] ?? 'auto'),
                            (string) ($data['custom_separator'] ?? '---'),
                            (bool) ($data['skip_duplicates'] ?? true)
                        );

                        $this->refreshOwner();

                        Notification::make()
                            ->title("{$added} stock unit(s) imported into vault!")
                            ->body('Live available stock has been updated automatically.')
                            ->success()
                            ->send();
                    }),
                Action::make('exportAvailable')
                    ->label('Export Available (.TXT)')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(function (): StreamedResponse {
                        $product = $this->owner();
                        $available = $product->stockItems()
                            ->where('status', StockStatus::Available)
                            ->orderBy('id')
                            ->get()
                            ->map(fn (StockItem $s): string => trim((string) $s->content))
                            ->filter()
                            ->implode("\n---\n");

                        $filename = ($product->slug ?: 'product').'-available-stock-'.now()->format('Y-m-d').'.txt';

                        return response()->streamDownload(function () use ($available): void {
                            echo $available;
                        }, $filename, ['Content-Type' => 'text/plain; charset=UTF-8']);
                    }),
            ])
            ->recordActions([
                Action::make('reveal')
                    ->label('View Full')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (StockItem $record): string => "Stock Unit #{$record->id} ({$record->status->value})")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (StockItem $record): array => [
                        TextEntry::make('content')
                            ->label('Full Credential Content (1 PC)')
                            ->state($record->content)
                            ->copyable()
                            ->fontFamily('mono'),
                    ]),
                DeleteAction::make()
                    ->visible(fn (StockItem $record): bool => $record->status === StockStatus::Available)
                    ->after(fn () => $this->refreshOwner()),
            ])
            ->toolbarActions([
                BulkAction::make('deleteAvailable')
                    ->label('Delete selected (available only)')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $deleted = StockItem::query()
                            ->whereKey($records->modelKeys())
                            ->where('product_id', $this->owner()->getKey())
                            ->where('status', StockStatus::Available)
                            ->delete();

                        $this->refreshOwner();

                        Notification::make()->title("{$deleted} stock item(s) deleted")->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    protected function owner(): Product
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $product;
    }

    protected function refreshOwner(): void
    {
        Listings::refresh($this->owner()->refresh());
        $this->dispatch('product-refreshed');
    }
}
