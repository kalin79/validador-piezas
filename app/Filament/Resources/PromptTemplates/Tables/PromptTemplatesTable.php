<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptTemplates\Tables;

use App\Enums\RuleSetStatus;
use App\Models\PromptTemplate;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PromptTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'brand.client']))
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->description(fn (PromptTemplate $r): string => $r->key),

                TextColumn::make('version')
                    ->label('Version')
                    ->alignCenter()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => 'v'.$state),

                // Un solo dato en vez de dos columnas medio vacias: lo que
                // importa es a quien aplica, no si el campo cliente o marca
                // tiene valor.
                TextColumn::make('alcance')
                    ->label('Alcance')
                    ->badge()
                    ->state(fn (PromptTemplate $r): string => $r->alcance())
                    ->color(fn (PromptTemplate $r): string => match (true) {
                        $r->brand_id !== null => 'success',
                        $r->client_id !== null => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (RuleSetStatus $state): string => $state->label())
                    ->color(fn (RuleSetStatus $state): string => $state->color()),

                TextColumn::make('tamano')
                    ->label('Extension')
                    ->alignEnd()
                    ->state(fn (PromptTemplate $r): string => number_format(
                        (mb_strlen((string) $r->system_prompt) + mb_strlen((string) $r->user_prompt_template)) / 4
                    ).' tokens aprox.')
                    ->description('cuesta en cada validacion')
                    ->toggleable(),

                TextColumn::make('published_at')
                    ->label('Publicada')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('sin publicar')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        RuleSetStatus::Draft->value => 'Borrador',
                        RuleSetStatus::Published->value => 'Publicado',
                        RuleSetStatus::Retired->value => 'Retirado',
                    ]),

                SelectFilter::make('client_id')
                    ->label('Cliente')
                    ->relationship('client', 'name', fn (\Illuminate\Database\Eloquent\Builder $query) => \App\Support\Alcance::clientesVisibles($query))
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ver'),

                EditAction::make()
                    ->label('Editar'),

                Action::make('nueva_version')
                    ->label('Nueva version')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Crear una version nueva')
                    ->modalDescription(fn (PromptTemplate $r): string => 'Se copia el contenido a un borrador editable con el mismo alcance ('
                        .$r->alcance().'). Esta version queda intacta y sigue vigente hasta que publiques la nueva.')
                    ->visible(fn (PromptTemplate $r): bool => $r->status !== RuleSetStatus::Draft)
                    ->authorize(fn (PromptTemplate $record): bool => (bool) auth()->user()?->can('create', PromptTemplate::class)
                        && (bool) auth()->user()?->can('view', $record))
                    ->action(function (PromptTemplate $record) {
                        $siguiente = PromptTemplate::siguienteVersion(
                            $record->key,
                            $record->client_id,
                            $record->brand_id,
                        );

                        $nueva = PromptTemplate::create([
                            'client_id' => $record->client_id,
                            'brand_id' => $record->brand_id,
                            'key' => $record->key,
                            'version' => $siguiente,
                            'name' => preg_replace('/ v\d+$/', '', $record->name)." v{$siguiente}",
                            'system_prompt' => $record->system_prompt,
                            'user_prompt_template' => $record->user_prompt_template,
                            'output_schema' => $record->output_schema,
                            'status' => RuleSetStatus::Draft->value,
                        ]);

                        Notification::make()
                            ->title("Borrador v{$siguiente} creado")
                            ->body('Editalo y publicalo cuando este listo.')
                            ->success()
                            ->send();

                        return redirect(\App\Filament\Resources\PromptTemplates\PromptTemplateResource::getUrl('edit', ['record' => $nueva]));
                    }),

                DeleteAction::make()
                    ->visible(fn (PromptTemplate $r): bool => $r->status === RuleSetStatus::Draft),
            ])
            ->emptyStateHeading('Sin instrucciones')
            ->emptyStateDescription('Corre el seeder de plantillas o crea una desde cero.');
    }
}
