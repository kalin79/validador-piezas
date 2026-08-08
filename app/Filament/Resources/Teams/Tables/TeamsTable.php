<?php

declare(strict_types=1);

namespace App\Filament\Resources\Teams\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Equipo')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('users_count')
                    ->label('Miembros')
                    ->counts('users')
                    ->badge()
                    ->alignCenter(),

                TextColumn::make('clients.name')
                    ->label('Clientes completos')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),

                TextColumn::make('brands.name')
                    ->label('Marcas puntuales')
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),

                IconColumn::make('is_external')
                    ->label('Externo')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('gray'),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_external')->label('Externo'),
                TernaryFilter::make('is_active')->label('Activo'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
