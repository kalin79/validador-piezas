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
                        ->helperText('Claves sugeridas: jurisdiction, contrast_threshold, delta_e_default, retention_days'),
                ]),
        ]);
    }
}
