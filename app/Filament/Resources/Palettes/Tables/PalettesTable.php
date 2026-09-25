<?php

declare(strict_types=1);

namespace App\Filament\Resources\Palettes\Tables;

use App\Models\Brand;
use App\Models\Palette;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class PalettesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('brand.client'))
            ->columns([
                TextColumn::make('brand.client.name')
                    ->label('Cliente')
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('brand.name')
                    ->label('Marca')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Paleta')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('colors_count')
                    ->label('Colores')
                    ->counts('colors')
                    ->badge()
                    ->alignCenter(),

                TextColumn::make('default_delta_e_tolerance')
                    ->label('Delta E')
                    ->alignCenter(),

                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('client')
                    ->label('Cliente')
                    ->relationship('brand.client', 'name', fn (\Illuminate\Database\Eloquent\Builder $query) => \App\Support\Alcance::clientesVisibles($query))
                    ->searchable()
                    ->preload(),

                SelectFilter::make('brand_id')
                    ->label('Marca')
                    ->relationship('brand', 'name', fn (\Illuminate\Database\Eloquent\Builder $query) => \App\Support\Alcance::marcasVisibles($query))
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),

                // Duplicar existe porque una paleta no puede pertenecer a un
                // cliente: la tabla exige brand_id. Cuando varias unidades de
                // un mismo cliente comparten identidad visual, hay que cargar
                // los mismos colores una y otra vez.
                //
                // Es una solucion de conveniencia, no de arquitectura: las
                // copias quedan independientes. La salida definitiva es la
                // herencia a nivel cliente, y cuando exista estas copias se
                // colapsan en una.
                ActionGroup::make([
                    Action::make('duplicar_todas')
                        ->label('En todas las marcas del cliente')
                        ->icon('heroicon-o-square-2-stack')
                        ->requiresConfirmation()
                        ->modalHeading('Duplicar en todas las marcas')
                        ->modalDescription(fn (Palette $record): string => sprintf(
                            'Se creara una copia de "%s" con sus %d color(es) en cada marca de %s que aun no tenga una paleta con ese nombre. '
                            .'Las copias quedan independientes: cambiar un color aqui no lo cambia alla.',
                            $record->name,
                            $record->colors()->count(),
                            $record->brand?->client?->name ?? 'este cliente',
                        ))
                        ->action(function (Palette $record): void {
                            $destinos = self::marcasHermanas($record);

                            if ($destinos->isEmpty()) {
                                Notification::make()
                                    ->title('No hay marcas donde duplicar')
                                    ->body('Todas las marcas del cliente ya tienen una paleta con ese nombre.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $creadas = 0;

                            foreach ($destinos as $marca) {
                                self::duplicarEn($record, $marca);
                                $creadas++;
                            }

                            Notification::make()
                                ->title("{$creadas} paleta(s) creada(s)")
                                ->body('Revisa que los colores hayan quedado completos en cada una.')
                                ->success()
                                ->send();
                        }),

                    Action::make('duplicar_misma')
                        ->label('Otra copia en esta misma marca')
                        ->icon('heroicon-o-document-duplicate')
                        ->requiresConfirmation()
                        ->modalDescription('Util para partir de una paleta existente y ajustarla. Se crea con el sufijo "copia".')
                        ->action(function (Palette $record): void {
                            $nueva = self::duplicarEn($record, $record->brand, $record->name.' (copia)');

                            Notification::make()
                                ->title('Copia creada')
                                ->body($nueva->name)
                                ->success()
                                ->send();
                        }),
                ])
                    ->label('Duplicar')
                    ->icon('heroicon-o-square-2-stack')
                    ->color('gray')
                    ->button(),

                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Marcas del mismo cliente que aun no tienen una paleta con ese nombre.
     *
     * Se filtra por nombre y no solo por marca para poder duplicar una paleta
     * secundaria en marcas que ya tienen la institucional.
     *
     * @return \Illuminate\Support\Collection<int, Brand>
     */
    private static function marcasHermanas(Palette $palette)
    {
        return Brand::query()
            ->where('client_id', $palette->brand?->client_id)
            ->where('id', '!=', $palette->brand_id)
            ->whereIn('id', auth()->user()->accessibleBrandIds())
            ->whereDoesntHave('palettes', fn ($q) => $q->where('name', $palette->name))
            ->orderBy('name')
            ->get();
    }

    /**
     * Copia la paleta y sus colores a una marca destino.
     *
     * Se escribe campo por campo y no con replicate(): asi ningun atributo
     * calculado ni marca de tiempo se arrastra sin que sea explicito, y queda
     * a la vista que se copia.
     */
    private static function duplicarEn(Palette $origen, ?Brand $destino, ?string $nombre = null): Palette
    {
        return DB::transaction(function () use ($origen, $destino, $nombre): Palette {
            $nueva = Palette::create([
                'brand_id' => $destino?->id ?? $origen->brand_id,
                'name' => $nombre ?? $origen->name,
                'description' => $origen->description,
                'default_delta_e_tolerance' => $origen->default_delta_e_tolerance,
                'is_active' => $origen->is_active,
            ]);

            foreach ($origen->colors()->orderBy('sort_order')->get() as $color) {
                $nueva->colors()->create([
                    'name' => $color->name,
                    'hex' => $color->hex,
                    'lab' => $color->lab,
                    'role' => $color->role instanceof \BackedEnum ? $color->role->value : $color->role,
                    'delta_e_tolerance' => $color->delta_e_tolerance,
                    'is_forbidden' => $color->is_forbidden,
                    'sort_order' => $color->sort_order,
                ]);
            }

            return $nueva;
        });
    }
}
