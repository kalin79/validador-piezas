<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Schemas;

use App\Models\Brand;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SubmissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Marca de destino')
                ->description('Confirma la marca antes de cargar. Una pieza validada contra las reglas equivocadas da un veredicto inutil.')
                ->schema([
                    Select::make('brand_id')
                        ->label('Marca')
                        ->options(fn (): array => Brand::query()
                            ->whereIn('id', auth()->user()->accessibleBrandIds())
                            ->where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn (Brand $b): array => [$b->id => $b->fullName()])
                            ->all())
                        ->default(fn () => auth()->user()->active_brand_id)
                        ->required()
                        ->searchable()
                        ->helperText(fn (string $operation): string => $operation === 'create'
                            ? 'Viene del contexto de trabajo. Cambiala aqui solo si esta carga es para otra marca.'
                            : 'Cambiarla no reevalua las piezas ya validadas: cada pieza guarda la marca con la que ingreso.'),
                ]),

            Section::make('Contexto de la pieza')
                ->columns(2)
                ->schema([
                    Select::make('channel')
                        ->label('Canal de publicacion')
                        ->options(fn (): array => collect(config('channels.presets', []))
                            ->map(fn (array $p): string => $p['label'])
                            ->all())
                        ->searchable()
                        ->required()
                        ->helperText('Define contra que dimensiones y peso se valida el formato.'),

                    TextInput::make('campaign')
                        ->label('Campana')
                        ->maxLength(255),

                    TextInput::make('product')
                        ->label('Producto')
                        ->maxLength(255),

                    Textarea::make('objective')
                        ->label('Objetivo de la pieza')
                        ->rows(2)
                        ->helperText('Que se busca lograr. Sirve al modelo para juzgar coherencia de mensaje en la fase de IA.')
                        ->columnSpanFull(),

                    Textarea::make('notes')
                        ->label('Notas para el revisor')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make(fn (string $operation): string => $operation === 'create'
                ? 'Piezas'
                : 'Agregar piezas a esta carga')
                ->description(fn (string $operation): ?string => $operation === 'create'
                    ? null
                    : 'Lo que subas aqui se agrega como pieza nueva. Las piezas ya cargadas no se tocan ni se reemplazan: quedan abajo con su historial completo.')
                ->schema([
                    FileUpload::make('archivos')
                        ->label(fn (string $operation): string => $operation === 'create'
                            ? 'Archivos'
                            : 'Archivos nuevos')
                        ->multiple()
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(20 * 1024)
                        ->maxFiles(20)
                        ->directory(fn (): string => 'piezas/'.now()->format('Y/m'))
                        // El nombre de almacenamiento lleva los primeros 8 caracteres
                        // del SHA-256 del contenido. Es deterministico: los mismos
                        // bytes producen siempre la misma ruta, y bytes distintos
                        // producen rutas distintas.
                        //
                        // Antes iba preserveFilenames(), y eso permitia que resubir
                        // una version corregida con el mismo nombre de archivo
                        // sobreescribiera en disco el original de una pieza ya
                        // validada. La fila de esa pieza seguia apuntando a la misma
                        // ruta, pero los bytes eran otros: la evidencia dejaba de
                        // corresponder al veredicto, en silencio.
                        ->getUploadedFileNameForStorageUsing(function ($file): string {
                            $nombre = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
                            $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
                            $huella = substr((string) hash_file('sha256', $file->getRealPath()), 0, 8);

                            $nombre = $nombre !== '' ? Str::limit($nombre, 80, '') : 'pieza';

                            return "{$nombre}-{$huella}.{$extension}";
                        })
                        ->imagePreviewHeight('160')
                        ->panelLayout('grid')
                        ->reorderable(false)
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(true)
                        ->helperText('JPG, PNG o WEBP. Hasta 20 archivos de 20 MB cada uno.'),
                ]),
        ]);
    }
}
