<?php

namespace App\Filament\Resources\MediaItems;

use App\Filament\Resources\MediaItems\Pages\ListMediaItems;
use App\Models\MediaItem;
use App\Models\Product;
use App\Services\ImageOptimizer;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class MediaItemResource extends Resource
{
    protected static ?string $model = MediaItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Media Gallery';

    protected static ?string $modelLabel = 'Gallery Image';

    protected static ?string $pluralModelLabel = 'Media Gallery';

    protected static ?string $slug = 'gallery';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label('Image Name (for searching)')
                    ->placeholder('e.g. Netflix 4K, Canva Pro, Easypaisa Logo')
                    ->required()
                    ->maxLength(150),
                FileUpload::make('path')
                    ->label('Image File')
                    ->helperText('Replacing this image automatically updates the picture on all Products & Payment Methods using it.')
                    ->image()
                    ->disk('public')
                    ->directory('gallery')
                    ->visibility('public')
                    ->maxSize(4096)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('path')
                    ->label('Preview')
                    ->state(fn (MediaItem $record): ?string => $record->url())
                    ->square()
                    ->imageSize(56),
                TextColumn::make('name')
                    ->label('Image Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('linked_products')
                    ->label('Linked Products')
                    ->badge()
                    ->color('info')
                    ->state(function (MediaItem $record): string {
                        $count = Product::withTrashed()->where('image', $record->path)->count();

                        return $count > 0 ? "{$count} product".($count === 1 ? '' : 's') : 'Unused';
                    }),
                TextColumn::make('path')
                    ->label('File Path')
                    ->copyable()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('size')
                    ->label('Weight (KB)')
                    ->badge()
                    ->color('success')
                    ->state(fn (MediaItem $record): string => ImageOptimizer::formatKb($record->size ?: (Storage::disk('public')->exists((string) $record->path) ? Storage::disk('public')->size((string) $record->path) : null)))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->since()
                    ->sortable(),
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

    public static function getPages(): array
    {
        return [
            'index' => ListMediaItems::route('/'),
        ];
    }
}
