<?php

declare(strict_types=1);

namespace App\Filament\Resources\RuleSets\Schemas;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Models\Brand;
use App\Models\Client;
use App\Models\RuleSet;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Vista de solo lectura de un conjunto de reglas.
 *
 * Un conjunto publicado es inmutable, y hasta ahora eso se traducia en que no
 * habia forma de abrirlo: la fila del listado mostraba nombre, version y
 * estado, y nada mas. Para responder "que decia la v3" habia que crear una
 * version nueva o entrar a la base de datos.
 *
 * Eso importa mas de lo que parece en un sistema de auditoria. Cuando un
 * cliente pregunta por que se rechazo una pieza hace dos meses, la respuesta
 * esta en las reglas vigentes ese dia, que casi nunca son las de hoy.
 *
 * Se escribe un infolist propio en vez de dejar que Filament reutilice el
 * formulario deshabilitado porque el formulario esta construido para capturar
 * datos: tiene selects que resuelven opciones con consultas, campos que se
 * ocultan segun el estado de otros y un calculo de version que no tiene
 * sentido fuera de la creacion. Aqui solo hay que mostrar hechos.
 */
class RuleSetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Alcance')
                ->columns(3)
                ->schema([
                    TextEntry::make('client.name')
                        ->label('Cliente')
                        ->placeholder('—'),

                    TextEntry::make('owner_type')
                        ->label('Nivel')
                        ->badge()
                        ->formatStateUsing(fn (RuleSetOwnerType $state): string => $state->label())
                        ->color(fn (RuleSetOwnerType $state): string => $state === RuleSetOwnerType::Client ? 'warning' : 'info'),

                    /*
                     * El dueno es polimorfico manual (owner_type + owner_id), asi
                     * que no hay relacion de Eloquent que resolver: se consulta la
                     * tabla que corresponde segun el tipo. Mostrar el id crudo
                     * obligaria a cruzarlo a mano con otra pantalla.
                     */
                    TextEntry::make('owner_id')
                        ->label('Aplica a')
                        ->state(function (RuleSet $record): string {
                            if ($record->owner_type === RuleSetOwnerType::Brand) {
                                return Brand::query()->find($record->owner_id)?->name
                                    ?? "Marca #{$record->owner_id} (no encontrada)";
                            }

                            return Client::query()->find($record->owner_id)?->name
                                ?? "Cliente #{$record->owner_id} (no encontrado)";
                        })
                        ->helperText(fn (RuleSet $record): string => $record->owner_type === RuleSetOwnerType::Client
                            ? 'Lo heredan todas las marcas de este cliente.'
                            : 'Aplica solo a esta marca y puede anular lo heredado.'),
                ]),

            Section::make('Version')
                ->columns(3)
                ->schema([
                    TextEntry::make('name')
                        ->label('Nombre')
                        ->weight('medium'),

                    TextEntry::make('version')
                        ->label('Version')
                        ->badge(),

                    TextEntry::make('status')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn (RuleSetStatus $state): string => $state->label())
                        ->color(fn (RuleSetStatus $state): string => $state->color())
                        ->helperText(fn (RuleSet $record): string => match ($record->status) {
                            RuleSetStatus::Published => 'Es la version vigente. Las validaciones de hoy usan estas reglas.',
                            RuleSetStatus::Retired => 'Ya no se usa en validaciones nuevas, pero las piezas juzgadas con ella la siguen referenciando.',
                            default => 'Todavia no se aplica a ninguna validacion.',
                        }),

                    TextEntry::make('changelog')
                        ->label('Que cambia respecto de la version anterior')
                        ->placeholder('Sin nota de cambios')
                        ->columnSpanFull(),
                ]),

            Section::make('Trazabilidad')
                ->columns(3)
                ->collapsed()
                ->schema([
                    TextEntry::make('published_at')
                        ->label('Publicado')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),

                    // Se resuelve con una consulta directa en vez de una relacion
                    // para no depender de que el modelo la declare.
                    TextEntry::make('published_by')
                        ->label('Publicado por')
                        ->state(fn (RuleSet $record): ?string => $record->published_by !== null
                            ? User::query()->find($record->published_by)?->name
                            : null)
                        ->placeholder('—'),

                    TextEntry::make('created_at')
                        ->label('Creado')
                        ->dateTime('d/m/Y H:i'),
                ]),
        ]);
    }
}
