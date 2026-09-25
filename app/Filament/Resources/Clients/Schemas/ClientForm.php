<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificacion')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre comercial')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

                    TextInput::make('slug')
                        ->label('Identificador')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Se usa en URLs. Cambiarlo despues rompe enlaces guardados.'),

                    TextInput::make('legal_name')
                        ->label('Razon social')
                        ->maxLength(255),

                    TextInput::make('tax_id')
                        ->label('RUC')
                        ->maxLength(20),

                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true),
                ]),

            Section::make('Configuracion heredada por las marcas')
                ->description('Las marcas de este cliente heredan estos valores, y pueden sobrescribirlos en su propia configuracion.')
                ->collapsed()
                ->schema([
                    KeyValue::make('settings')
                        ->label('Parametros')
                        ->keyLabel('Clave')
                        ->valueLabel('Valor')
                        ->addActionLabel('Agregar parametro')
                        /*
                         * El texto anterior sugeria jurisdiction, delta_e_default
                         * y retention_days, tres claves que ningun punto del
                         * codigo consulta. Quien las llenaba esperaba un efecto
                         * que nunca llegaba.
                         *
                         * Se listan las que de verdad se leen, con su formato,
                         * porque scoring_weights es la unica que no es un numero
                         * suelto y escribirla mal la vuelve inservible.
                         */
                        ->helperText(
                            'Solo se leen estas cuatro claves. '
                            .'contrast_threshold: numero, 4.5 por omision (WCAG AA). '
                            .'observation_threshold: numero, 90 por omision. '
                            .'rejection_threshold: numero, 50 por omision. Bajo ese puntaje la pieza se rechaza aunque no haya bloqueantes. '
                            .'scoring_weights: JSON, por omision {"blocking":0,"major":15,"minor":5,"info":0}. '
                            .'Cualquier otra clave se guarda pero el sistema no la usa.'
                        ),
                ]),
        ]);
    }
}
