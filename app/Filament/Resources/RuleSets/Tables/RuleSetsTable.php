<?php

declare(strict_types=1);

namespace App\Filament\Resources\RuleSets\Tables;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Models\Rule;
use App\Models\RuleSet;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class RuleSetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->color('gray'),

                TextColumn::make('owner_type')
                    ->label('Nivel')
                    ->badge()
                    ->formatStateUsing(fn (RuleSetOwnerType $state): string => $state->label())
                    ->color(fn (RuleSetOwnerType $state): string => $state === RuleSetOwnerType::Client ? 'warning' : 'info'),

                TextColumn::make('name')
                    ->label('Conjunto')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('version')
                    ->label('v')
                    ->badge()
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('rules_count')
                    ->label('Reglas')
                    ->counts('rules')
                    ->alignCenter(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (RuleSetStatus $state): string => $state->label())
                    ->color(fn (RuleSetStatus $state): string => $state->color()),

                TextColumn::make('published_at')
                    ->label('Publicado')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(fn (): array => collect(RuleSetStatus::cases())
                        ->mapWithKeys(fn (RuleSetStatus $s): array => [$s->value => $s->label()])
                        ->all()),

                SelectFilter::make('client_id')
                    ->label('Cliente')
                    ->relationship('client', 'name', fn (\Illuminate\Database\Eloquent\Builder $query) => \App\Support\Alcance::clientesVisibles($query))
                    ->searchable()
                    ->preload(),

                SelectFilter::make('owner_type')
                    ->label('Nivel')
                    ->options([
                        RuleSetOwnerType::Client->value => 'Cliente',
                        RuleSetOwnerType::Brand->value => 'Marca',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ver'),

                Action::make('publish')
                    ->label('Publicar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Publicar este conjunto de reglas')
                    ->modalDescription('Al publicar, la version vigente anterior pasa a Retirado y esta queda como unica activa. No podras editar sus reglas: para cambiarlas tendras que crear una version nueva. Las validaciones que ya usaron versiones anteriores las seguiran referenciando para siempre.')
                    ->visible(fn (RuleSet $record): bool => $record->status === RuleSetStatus::Draft)
                    // Se verifica en el servidor al ejecutar, no solo al pintar
                    // el boton: RuleSetPolicy::publish exige knowledge.publish
                    // (o publish_client en nivel cliente) y alcance.
                    ->authorize('publish')
                    ->action(function (RuleSet $record): void {
                        // Bloqueo, verificacion y retiro en una sola transaccion
                        // (ver App\Services\Publicacion).
                        try {
                            $retiradas = app(\App\Services\Publicacion::class)->publicarConjunto($record, auth()->id());
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title('No se publico')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title("Version {$record->version} vigente")
                            ->body($retiradas > 0
                                ? "Se retiro {$retiradas} version(es) anterior(es)."
                                : 'Es la primera version publicada de este conjunto.')
                            ->success()
                            ->send();
                    }),

                Action::make('newVersion')
                    ->label('Nueva version')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Crear una version nueva')
                    ->modalDescription('Se generara una copia en borrador con todas las reglas de esta version, para editarla sin tocar la publicada.')
                    ->visible(fn (RuleSet $record): bool => $record->status === RuleSetStatus::Published)
                    ->authorize(fn (RuleSet $record): bool => (bool) auth()->user()?->can('create', RuleSet::class)
                        && (bool) auth()->user()?->can('view', $record))
                    ->action(function (RuleSet $record): void {
                        $copia = DB::transaction(function () use ($record): RuleSet {
                            $version = $record->nextVersionNumber();

                            // Se construye explicitamente en vez de usar replicate():
                            // el modelo trae atributos agregados por withCount (rules_count)
                            // que no son columnas y romperian el INSERT.
                            $copia = RuleSet::create([
                                'owner_type' => $record->owner_type,
                                'owner_id' => $record->owner_id,
                                'client_id' => $record->client_id,
                                'version' => $version,
                                'name' => self::nombreConVersion($record->name, $version),
                                'changelog' => null,
                                'status' => RuleSetStatus::Draft,
                                'published_at' => null,
                                'published_by' => null,
                                'created_by' => auth()->id(),
                            ]);

                            foreach ($record->rules()->get() as $regla) {
                                /*
                                 * replicate() copia los atributos tal como estan
                                 * en la base y save() los inserta sin volver a
                                 * castearlos.
                                 *
                                 * La version anterior pasaba getAttributes() a
                                 * create(), y ahi estaba el bug: getAttributes()
                                 * devuelve los valores CRUDOS, o sea las columnas
                                 * json como cadena. Al entrar por create(), el
                                 * cast 'array' del modelo las codificaba una
                                 * segunda vez y quedaban como '"[\"texto\"]"'.
                                 *
                                 * El sintoma aparecia despues, al abrir el
                                 * conjunto copiado: el Repeater de ejemplos
                                 * recibia una cadena donde esperaba un arreglo y
                                 * reventaba con un 500 que no señalaba el origen.
                                 * Cada "Nueva version" corrompia un poco mas los
                                 * datos.
                                 */
                                $nueva = $regla->replicate();
                                $nueva->rule_set_id = $copia->getKey();
                                $nueva->save();
                            }

                            return $copia;
                        });

                        Notification::make()
                            ->title("Version {$copia->version} creada en borrador")
                            ->body("Se copiaron {$copia->rules()->count()} regla(s). Editala y publicala cuando este lista.")
                            ->success()
                            ->send();
                    }),

                EditAction::make(),

                DeleteAction::make()
                    ->visible(fn (RuleSet $record): bool => $record->status === RuleSetStatus::Draft),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Reemplaza el sufijo de version en el nombre, o lo agrega si no lo tenia.
     * "Reglas de marca Pro v1" con version 2 devuelve "Reglas de marca Pro v2".
     */
    private static function nombreConVersion(string $nombre, int $version): string
    {
        $base = preg_replace('/\s*v\d+\s*$/i', '', $nombre) ?? $nombre;

        return trim($base)." v{$version}";
    }
}
