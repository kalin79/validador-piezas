<?php

declare(strict_types=1);

namespace App\Filament\Resources\RuleSets\Schemas;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Models\Brand;
use App\Models\Client;
use App\Support\Alcance;
use App\Models\RuleSet;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RuleSetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Alcance del conjunto')
                ->description('Un conjunto de cliente lo heredan todas sus marcas. Uno de marca aplica solo a esa marca y puede sobrescribir lo heredado.')
                ->columns(2)
                ->schema([
                    Select::make('owner_type')
                        ->label('Nivel')
                        ->options([
                            RuleSetOwnerType::Client->value => RuleSetOwnerType::Client->label(),
                            RuleSetOwnerType::Brand->value => RuleSetOwnerType::Brand->label(),
                        ])
                        ->required()
                        ->live()
                        ->disabledOn('edit')
                        ->afterStateUpdated(function (callable $set, callable $get): void {
                            $set('owner_id', null);
                            $set('version', self::siguienteVersion($get));
                        }),

                    Select::make('client_id')
                        ->label('Cliente')
                        // Nivel cliente exige acceso completo al cliente; nivel
                        // marca basta con ver alguna de sus marcas.
                        ->options(fn (callable $get): array => ($get('owner_type') === RuleSetOwnerType::Client->value
                            ? Alcance::clientesCompletos(Client::query())
                            : Alcance::clientesVisibles(Client::query()))
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabledOn('edit')
                        ->afterStateUpdated(fn (callable $set, callable $get) => $set('version', self::siguienteVersion($get))),

                    Select::make('owner_id')
                        ->label('Marca')
                        ->options(function (callable $get): array {
                            if ($get('owner_type') !== RuleSetOwnerType::Brand->value || blank($get('client_id'))) {
                                return [];
                            }

                            return Alcance::marcas(Brand::query())
                                ->where('client_id', $get('client_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->live()
                        ->required(fn (callable $get): bool => $get('owner_type') === RuleSetOwnerType::Brand->value)
                        ->visible(fn (callable $get): bool => $get('owner_type') === RuleSetOwnerType::Brand->value)
                        ->disabledOn('edit')
                        ->afterStateUpdated(fn (callable $set, callable $get) => $set('version', self::siguienteVersion($get))),
                ]),

            Section::make('Version')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('version')
                        ->label('Version')
                        ->numeric()
                        ->required()
                        ->default(1)
                        ->disabledOn('edit')
                        ->helperText('Se calcula sola. Un conjunto publicado es inmutable: para cambiarlo se crea una version nueva.'),

                    // Publicar no se ofrece aqui a proposito: es una accion con
                    // consecuencias (congela las reglas, retira la version
                    // anterior) y debe pasar por el boton Publicar del listado,
                    // que confirma e impide publicar un conjunto vacio.
                    Select::make('status')
                        ->label('Estado')
                        ->options(fn (?RuleSet $record): array => $record?->status === RuleSetStatus::Published
                            ? [RuleSetStatus::Published->value => RuleSetStatus::Published->label()]
                            : [
                                RuleSetStatus::Draft->value => RuleSetStatus::Draft->label(),
                                RuleSetStatus::Retired->value => RuleSetStatus::Retired->label(),
                            ])
                        ->default(RuleSetStatus::Draft->value)
                        ->disabled(fn (?RuleSet $record): bool => $record?->status === RuleSetStatus::Published)
                        ->required()
                        ->helperText('Para publicar usa el boton Publicar del listado: confirma la accion, verifica que el conjunto tenga reglas y retira la version vigente anterior.'),

                    Textarea::make('changelog')
                        ->label('Que cambia respecto de la version anterior')
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText('Lo unico que le explica a un auditor por que cambio el criterio. El sistema registra que cambio; el porque solo lo sabes tu.'),
                ]),
        ]);
    }

    /**
     * Calcula la siguiente version disponible, contando tambien las borradas
     * logicamente: el indice unico de la base no las distingue.
     */
    private static function siguienteVersion(callable $get): int
    {
        $tipo = $get('owner_type');

        $ownerId = $tipo === RuleSetOwnerType::Client->value
            ? $get('client_id')
            : $get('owner_id');

        if (blank($tipo) || blank($ownerId)) {
            return 1;
        }

        return RuleSet::nextVersionFor($tipo, (int) $ownerId);
    }
}
