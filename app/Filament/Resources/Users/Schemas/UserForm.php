<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Brand;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    TextInput::make('password')
                        ->label('Contrasena')
                        ->password()
                        ->revealable()
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->helperText('Dejar vacio al editar para no cambiarla.'),

                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->helperText('Un usuario inactivo no puede entrar al panel. No se borra: la autoria de sus piezas se conserva.'),
                ]),

            Section::make('Rol y acceso')
                ->columns(2)
                ->schema([
                    Select::make('roles')
                        ->label('Roles')
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->helperText('super_admin y auditor ven todas las marcas sin pasar por equipos.'),

                    Select::make('teams')
                        ->label('Equipos')
                        ->relationship('teams', 'name')
                        ->multiple()
                        ->preload()
                        ->helperText('De aqui sale que marcas puede ver.'),

                    Select::make('active_brand_id')
                        ->label('Marca activa')
                        ->options(fn (): array => Brand::query()
                            ->with('client')
                            ->orderBy('client_id')
                            ->get()
                            ->mapWithKeys(fn (Brand $b): array => [$b->id => $b->fullName()])
                            ->all())
                        ->searchable()
                        ->placeholder('Sin contexto')
                        ->helperText('Contexto de trabajo, no permiso. Se valida contra los accesos reales.'),
                ]),
        ]);
    }
}
