<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Schemas;

use App\Models\Client;
use App\Support\Alcance;
use Illuminate\Database\Eloquent\Builder;
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
                        ->relationship('client', 'name', modifyQueryUsing: fn (Builder $query): Builder => Alcance::clientesCompletos($query))
                        ->searchable()
                        ->preload()
                        ->required()
                        // Mudar una marca a otro cliente cambia quien la ve y
                        // que reglas hereda. Solo super_admin puede hacerlo.
                        ->disabled(fn (string $operation): bool => $operation === 'edit' && ! Alcance::esSuperAdmin(auth()->user()))
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
                        ->addActionLabel('Agregar parametro')
                        // Las mismas claves que a nivel cliente. Lo que se
                        // escriba aqui gana sobre lo heredado; lo que se deje
                        // fuera se sigue heredando.
                        ->helperText(
                            'Mismas claves que en el cliente: contrast_threshold, observation_threshold, rejection_threshold '
                            .'y scoring_weights. Lo que definas aqui gana sobre lo heredado; '
                            .'lo que dejes fuera se sigue heredando del cliente.'
                        ),
                ]),
        ]);
    }
}
