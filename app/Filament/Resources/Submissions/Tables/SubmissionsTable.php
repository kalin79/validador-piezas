<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Tables;

use App\Models\Submission;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('brand.name')
                    ->label('Marca')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('campaign')
                    ->label('Campana')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('channel')
                    ->label('Canal')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : (config("channels.presets.{$state}.label") ?? $state))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('assets_count')
                    ->label('Piezas')
                    ->counts('assets')
                    ->alignCenter(),

                TextColumn::make('user.name')
                    ->label('Cargado por')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('brand_id')
                    ->label('Marca')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('user_id')
                    ->label('Diseñador')
                    ->options(fn (): array => Submission::cargadores())
                    ->searchable(),

                SelectFilter::make('channel')
                    ->label('Canal')
                    ->options(fn (): array => collect(config('channels.presets', []))
                        ->map(fn (array $p): string => $p['label'])
                        ->all()),
            ])
            ->recordActions([
                Action::make('revalidar')
                    ->label('Revalidar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Se creara una ejecucion nueva por cada pieza, con las reglas publicadas vigentes. Las validaciones anteriores se conservan.')
                    ->action(function (Submission $record): void {
                        $n = 0;

                        foreach ($record->assets as $asset) {
                            \App\Jobs\RunValidation::dispatch($asset, auth()->id());
                            $n++;
                        }

                        Notification::make()
                            ->title("Revalidacion lanzada sobre {$n} pieza(s)")
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
