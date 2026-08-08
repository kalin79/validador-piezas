<?php

declare(strict_types=1);

namespace App\Filament\Resources\BrandAssets\Schemas;

use App\Enums\BrandAssetType;
use App\Enums\LogoPosition;
use App\Models\Brand;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BrandAssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Activo')
                ->columns(2)
                ->schema([
                    Select::make('brand_id')
                        ->label('Marca')
                        ->options(fn (): array => Brand::query()
                            ->whereIn('id', auth()->user()->accessibleBrandIds())
                            ->get()
                            ->mapWithKeys(fn (Brand $b): array => [$b->id => $b->fullName()])
                            ->all())
                        ->default(fn () => auth()->user()->active_brand_id)
                        ->searchable()
                        ->required(),

                    Select::make('type')
                        ->label('Tipo')
                        ->options(BrandAssetType::options())
                        ->default(BrandAssetType::LogoPrimary->value)
                        ->required()
                        ->helperText('Sube una entrada por version. El logo en negativo no es el mismo archivo que el principal.'),

                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Como lo llaman internamente. Ej. "Logo Pro horizontal".'),

                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true),

                    Textarea::make('description')
                        ->label('Notas de uso')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Archivo de referencia')
                ->description('Idealmente PNG con fondo transparente y buena resolucion. Es contra este archivo que se compara la pieza.')
                ->schema([
                    FileUpload::make('storage_path')
                        ->label('Archivo')
                        ->image()
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
                        ->maxSize(10 * 1024)
                        ->disk('public')
                        ->directory('marca/logos')
                        ->imagePreviewHeight('120')
                        ->required(),
                ]),

            Section::make('Reglas de uso')
                ->description('Lo que se verificara sobre cada pieza. Dejar un campo vacio significa que esa regla no se evalua.')
                ->columns(2)
                ->schema([
                    Toggle::make('is_required')
                        ->label('Presencia obligatoria')
                        ->helperText('La pieza debe incluir este activo. Su ausencia genera hallazgo.')
                        ->columnSpanFull(),

                    TextInput::make('min_width_percent')
                        ->label('Ancho minimo (% de la pieza)')
                        ->numeric()
                        ->step(0.5)
                        ->suffix('%')
                        ->placeholder('No se evalua')
                        ->helperText('Un logo que ocupa menos del 8% del ancho suele volverse ilegible en movil.'),

                    TextInput::make('clear_space_ratio')
                        ->label('Area de resguardo')
                        ->numeric()
                        ->step(0.05)
                        ->placeholder('No se evalua')
                        ->helperText('Multiplo de la altura del propio logo. 0.5 significa medio logo de espacio libre alrededor.'),

                    CheckboxList::make('allowed_positions')
                        ->label('Posiciones permitidas')
                        ->options(LogoPosition::options())
                        ->columns(3)
                        ->columnSpanFull()
                        ->helperText('La pieza se divide en una malla de 3x3. Sin ninguna marcada, cualquier posicion es valida.'),

                    TagsInput::make('applies_to_channels')
                        ->label('Canales donde aplica')
                        ->placeholder('Dejar vacio para todos')
                        ->suggestions(fn (): array => array_keys(config('channels.presets', [])))
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
