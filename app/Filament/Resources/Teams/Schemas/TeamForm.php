<?php

declare(strict_types=1);

namespace App\Filament\Resources\Teams\Schemas;

use App\Models\Brand;
use App\Models\Client;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Equipo')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

                    TextInput::make('slug')
                        ->label('Identificador')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    Toggle::make('is_external')
                        ->label('Equipo externo')
                        ->helperText('Marca agencias y proveedores. No cambia permisos por si solo, sirve para filtrar reportes.'),

                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->helperText('Un equipo inactivo deja de otorgar acceso a sus miembros.'),

                    Textarea::make('description')
                        ->label('Descripcion')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Miembros')
                ->schema([
                    Select::make('users')
                        ->label('Usuarios')
                        ->relationship('users', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload(),
                ]),

            Section::make('Accesos')
                ->description('El acceso a nivel cliente incluye todas sus marcas, tambien las que se creen despues. El acceso por marca es puntual.')
                ->columns(2)
                ->schema([
                    Select::make('clients')
                        ->label('Clientes completos')
                        ->relationship('clients', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText('Da acceso a todas las marcas del cliente.'),

                    Select::make('brands')
                        ->label('Marcas puntuales')
                        ->relationship('brands', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->getOptionLabelFromRecordUsing(fn (Brand $record): string => $record->fullName())
                        ->helperText('Solo estas marcas. Innecesario si ya diste acceso al cliente.'),
                ]),
        ]);
    }
}
