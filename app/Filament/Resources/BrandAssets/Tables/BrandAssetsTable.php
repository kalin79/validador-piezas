<?php

declare(strict_types=1);

namespace App\Filament\Resources\BrandAssets\Tables;

use App\Enums\BrandAssetType;
use App\Enums\LogoPosition;
use App\Models\BrandAsset;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class BrandAssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('storage_path')
                    ->label('')
                    ->disk('public')
                    ->height(44),

                TextColumn::make('brand.name')
                    ->label('Marca')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Activo')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (BrandAsset $r): string => sprintf(
                        '%s × %s px',
                        $r->width ?? '?',
                        $r->height ?? '?'
                    )),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (BrandAssetType $state): string => $state->label()),

                IconColumn::make('is_required')
                    ->label('Obligatorio')
                    ->boolean(),

                TextColumn::make('min_width_percent')
                    ->label('Ancho min.')
                    ->suffix('%')
                    ->placeholder('—')
                    ->alignCenter(),

                TextColumn::make('clear_space_ratio')
                    ->label('Resguardo')
                    ->placeholder('—')
                    ->alignCenter(),

                // Se usa state() y no formatStateUsing(): cuando el valor de una
                // columna es un arreglo, Filament lo interpreta como multiples
                // valores y llama al formateador una vez por elemento. Con
                // state() se calcula la cadena completa antes de que eso ocurra.
                TextColumn::make('posiciones')
                    ->label('Posiciones')
                    ->state(fn (BrandAsset $record): string => blank($record->allowed_positions)
                        ? 'Cualquiera'
                        : count((array) $record->allowed_positions).' permitida(s)')
                    ->badge()
                    ->color(fn (BrandAsset $record): string => blank($record->allowed_positions) ? 'gray' : 'info')
                    ->tooltip(function (BrandAsset $record): ?string {
                        if (blank($record->allowed_positions)) {
                            return null;
                        }

                        return collect((array) $record->allowed_positions)
                            ->map(fn (string $p): string => LogoPosition::tryFrom($p)?->label() ?? $p)
                            ->implode(', ');
                    }),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('brand_id')
                    ->label('Marca')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(BrandAssetType::options()),

                TernaryFilter::make('is_required')->label('Obligatorio'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultGroup('brand.name');
    }
}
