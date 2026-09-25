<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Brand;
use App\Models\User;
use App\Support\Alcance;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

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
                        // Cambiarle la contrasena a otro es tomar su identidad.
                        // Solo un rol global lo hace; cada quien puede la suya.
                        ->visible(fn (?User $record): bool => $record === null
                            || auth()->user()?->can('changePassword', $record) === true)
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
                        /*
                         * Aqui estaba la escalada de privilegios: el campo se
                         * mostraba a cualquiera con acceso al panel, y bastaba
                         * asignarse super_admin para que hasGlobalAccess()
                         * abriera todos los clientes.
                         *
                         * El gate se consulta en vez de comprobar el rol a
                         * mano: la regla vive en UserPolicy y este formulario
                         * solo la obedece.
                         */
                        ->visible(fn (): bool => auth()->user()?->can('assignRoles', User::class) === true)
                        /*
                         * Se valida en el servidor y no solo se deshabilita la
                         * opcion en pantalla: esconder un control no es
                         * impedirlo, y esta combinacion abre todos los
                         * clientes.
                         */
                        ->rule(static fn (): Closure => static function (
                            string $attribute,
                            mixed $value,
                            Closure $fail
                        ): void {
                            $ids = array_filter(array_map('intval', (array) $value));

                            if (count($ids) < 2) {
                                return;
                            }

                            $auditor = Role::query()->where('name', 'auditor')->value('id');

                            if ($auditor !== null && in_array((int) $auditor, $ids, true)) {
                                $fail(
                                    'El rol auditor no se combina con otros. Da acceso a todos los '
                                    .'clientes sin pasar por equipos, y sumarlo a un rol que actua le '
                                    .'traslada ese alcance: auditor mas reviewer podria anular '
                                    .'veredictos de cualquier cliente.'
                                );
                            }
                        })
                        ->helperText('super_admin y auditor ven todas las marcas sin pasar por equipos. auditor va solo: no se combina con otros roles.'),

                    Select::make('teams')
                        ->label('Equipos')
                        // Solo equipos propios: asignar un equipo concede su
                        // acceso. Acotar la consulta tambien valida en el
                        // servidor (Filament rechaza ids fuera de las opciones).
                        ->relationship('teams', 'name', modifyQueryUsing: fn (Builder $query): Builder => Alcance::equipos($query))
                        ->rules([Alcance::reglaSoloNuevosPermitidos('teams', fn () => auth()->user()->teamIds())])
                        ->multiple()
                        ->preload()
                        // Los equipos son el mecanismo de aislamiento entre
                        // clientes: asignarlos equivale a conceder acceso.
                        ->visible(fn (): bool => auth()->user()?->hasPermissionTo('team.manage') === true)
                        ->helperText('De aqui sale que marcas puede ver.'),

                    Select::make('active_brand_id')
                        ->label('Marca activa')
                        ->options(fn (): array => Brand::query()
                            ->with('client')
                            ->when(
                                auth()->user()?->hasGlobalAccess() !== true,
                                fn ($q) => $q->whereIn('id', auth()->user()?->accessibleBrandIds() ?? [])
                            )
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
