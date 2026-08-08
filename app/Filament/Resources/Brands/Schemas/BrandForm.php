<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Schemas;

use App\Models\Client;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificacion')
                ->columns(2)
                ->schema([
                    Select::make('client_id')
                        ->label('Cliente')
                        ->relationship('client', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('La marca hereda las reglas corporativas de este cliente.'),

                    TextInput::make('name')
                        ->label('Marca')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

                    TextInput::make('slug')
                        ->label('Identificador')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Unico dentro del cliente. Dos clientes pueden repetirlo.'),

                    Toggle::make('is_active')
                        ->label('Activa')
                        ->default(true),

                    Textarea::make('description')
                        ->label('Descripcion')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Configuracion propia')
                ->description('Sobrescribe lo heredado del cliente. Dejar vacio para heredar.')
                ->collapsed()
                ->schema([
                    KeyValue::make('settings')
                        ->label('Parametros')
                        ->keyLabel('Clave')
                        ->valueLabel('Valor')
                        ->addActionLabel('Agregar parametro'),
                ]),
        ]);
    }
}
