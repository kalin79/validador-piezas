<?php

declare(strict_types=1);

namespace App\Filament\Resources\Palettes\Schemas;

use App\Models\Brand;
use App\Models\Client;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaletteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('A quien pertenece')
                ->description('El cliente solo filtra la lista de marcas. La paleta se guarda contra la marca.')
                ->columns(2)
                ->schema([
                    // Este campo no se guarda: palettes no tiene client_id.
                    // Existe para que el desplegable de marcas muestre solo las
                    // del cliente correcto. Sin el, la lista mezcla marcas de
                    // todos los clientes y elegir la equivocada es cuestion de
                    // tiempo -- y una paleta bajo la marca errada no falla:
                    // simplemente valida contra los colores de otro.
                    Select::make('client_filtro')
                        ->label('Cliente')
                        ->options(fn (): array => Client::query()
                            ->whereHas('brands', fn ($q) => $q->whereIn('id', auth()->user()->accessibleBrandIds()))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->live()
                        ->dehydrated(false)
                        ->afterStateUpdated(fn (callable $set) => $set('brand_id', null))
                        // Al editar se precarga desde la marca ya guardada,
                        // para que el desplegable de marcas no salga vacio.
                        ->afterStateHydrated(function (callable $set, callable $get): void {
                            $marca = $get('brand_id');

                            if (filled($marca)) {
                                $set('client_filtro', Brand::query()->whereKey($marca)->value('client_id'));
                            }
                        })
                        ->helperText('Elige primero el cliente.'),

                    Select::make('brand_id')
                        ->label('Marca')
                        ->options(function (callable $get): array {
                            if (blank($get('client_filtro'))) {
                                return [];
                            }

                            return Brand::query()
                                ->where('client_id', $get('client_filtro'))
                                ->whereIn('id', auth()->user()->accessibleBrandIds())
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->required()
                        ->disabled(fn (callable $get): bool => blank($get('client_filtro')))
                        ->helperText(fn (callable $get): string => blank($get('client_filtro'))
                            ? 'Se habilita al elegir un cliente.'
                            : 'Solo se listan las marcas de ese cliente.'),
                ]),

            Section::make('Definicion')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre de la paleta')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    TextInput::make('default_delta_e_tolerance')
                        ->label('Tolerancia Delta E por defecto')
                        ->numeric()
                        ->step(0.1)
                        ->default(5.0)
                        ->required()
                        ->helperText('Distancia maxima admitida en CIELAB. Bajo 1 es imperceptible; 2 a 3 exigente; 5 tolerante. Con degradados conviene 10 o mas.'),

                    Toggle::make('is_active')
                        ->label('Activa')
                        ->default(true),

                    Textarea::make('description')
                        ->label('Descripcion')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
