<?php

namespace App\Filament\Support;

use App\Models\MediaItem;
use App\Services\ImageOptimizer;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Reusable Media Gallery picker + uploader component for any image field (`image`, `logo`, etc.).
 * Lets admins search existing gallery images by name OR upload a new image with a searchable title.
 */
class GalleryPicker
{
    public static function make(string $field = 'image', string $label = 'Image', string $directory = 'gallery'): Grid
    {
        return Grid::make(['default' => 1, 'md' => 3])
            ->columnSpanFull()
            ->schema([
                Select::make($field)
                    ->label($label.' (Choose from Gallery or Upload New)')
                    ->placeholder('Search gallery by image name...')
                    ->searchable()
                    ->allowHtml()
                    ->live()
                    ->columnSpan(['default' => 1, 'md' => 2])
                    ->getSearchResultsUsing(fn (string $search): array => MediaItem::query()
                        ->where('name', 'like', "%{$search}%")
                        ->latest()
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn (MediaItem $item): array => [$item->path => $item->optionHtml()])
                        ->all())
                    ->options(fn (): array => MediaItem::query()
                        ->latest()
                        ->get()
                        ->mapWithKeys(fn (MediaItem $item): array => [$item->path => $item->optionHtml()])
                        ->all())
                    ->getOptionLabelUsing(function (?string $value): ?string {
                        if (! $value) {
                            return null;
                        }

                        $item = MediaItem::query()->where('path', $value)->first();
                        if ($item) {
                            return $item->optionHtml();
                        }

                        $url = e(Str::startsWith($value, ['http://', 'https://']) ? $value : url('/media/'.ltrim($value, '/')));

                        return '<div class="flex items-center gap-3 py-0.5">'
                            .'<img src="'.$url.'" alt="" class="h-9 w-9 shrink-0 rounded object-cover border border-gray-200 bg-gray-50" />'
                            .'<span class="font-medium text-sm truncate">'.e(basename($value)).'</span>'
                            .'</div>';
                    })
                    ->suffixAction(
                        Action::make('uploadToGallery_'.$field)
                            ->label('Upload new image')
                            ->icon(Heroicon::OutlinedArrowUpTray)
                            ->color('primary')
                            ->modalHeading('Upload Image to Gallery')
                            ->modalDescription('This image will be saved in your gallery so you can easily search and reuse it anytime.')
                            ->modalSubmitActionLabel('Save & Select')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Image Name (for easy search)')
                                    ->placeholder('e.g. ChatGPT Plus Logo, CapCut Pro Banner...')
                                    ->required()
                                    ->maxLength(150),
                                FileUpload::make('file')
                                    ->label('Image File')
                                    ->image()
                                    ->disk('public')
                                    ->directory($directory)
                                    ->visibility('public')
                                    ->maxSize(4096)
                                    ->required(),
                            ])
                            ->action(function (array $data, Set $set) use ($field): void {
                                $rawPath = (string) $data['file'];
                                $optimized = app(ImageOptimizer::class)->optimizePublicFile($rawPath);
                                $path = $optimized['path'];
                                $size = $optimized['new_size'] ?: (Storage::disk('public')->exists($path) ? Storage::disk('public')->size($path) : null);

                                $item = MediaItem::updateOrCreate(
                                    ['path' => $path],
                                    [
                                        'name' => trim($data['name']),
                                        'size' => $size,
                                    ]
                                );

                                $set($field, $item->path);

                                $sizeLabel = $optimized['old_size'] > 0 && $optimized['new_size'] > 0 && $optimized['old_size'] !== $optimized['new_size']
                                    ? ImageOptimizer::formatKb($optimized['old_size']).' → '.ImageOptimizer::formatKb($optimized['new_size']).' (WebP)'
                                    : ImageOptimizer::formatKb($size);

                                Notification::make()
                                    ->title('Uploaded, auto-compressed & selected!')
                                    ->body($item->name.' · '.$sizeLabel)
                                    ->success()
                                    ->send();
                            })
                    )
                    ->helperText('Type to search existing gallery images by name, or click the upload icon on the right to add a new image.'),

                TextEntry::make($field.'_preview')
                    ->label('Selected Preview')
                    ->columnSpan(['default' => 1, 'md' => 1])
                    ->state(function (Get $get) use ($field): HtmlString {
                        $path = (string) ($get($field) ?? '');
                        if ($path === '') {
                            return new HtmlString('<div class="flex h-24 items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50 text-xs text-gray-400">No image selected</div>');
                        }

                        $url = e(Str::startsWith($path, ['http://', 'https://']) ? $path : url('/media/'.ltrim($path, '/')));

                        return new HtmlString('<img src="'.$url.'" alt="Preview" class="h-24 w-auto max-w-full rounded-lg border border-gray-200 object-contain bg-white p-1 shadow-xs" />');
                    }),
            ]);
    }
}
