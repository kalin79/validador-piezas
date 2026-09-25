<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Bitacora de auditoria: quien hizo que y cuando.
 *
 * Solo lectura. Los registros son inmutables (modelo y triggers de base), asi
 * que aqui no hay acciones de editar ni borrar.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Accesos';

    protected static ?string $navigationLabel = 'Bitacora';

    protected static ?string $modelLabel = 'registro';

    protected static ?string $pluralModelLabel = 'Bitacora';

    protected static ?int $navigationSort = 90;

    /** Etiquetas legibles de las acciones que registra el sistema. */
    public const ACCIONES = [
        'auth.login' => 'Ingreso al panel',
        'auth.failed' => 'Ingreso fallido',
        'auth.logout' => 'Salida del panel',
        'auth.api_login' => 'Ingreso por API',
        'auth.api_logout' => 'Salida por API',
        'user.created' => 'Usuario creado',
        'user.roles_changed' => 'Roles modificados',
        'user.activated' => 'Usuario activado',
        'user.deactivated' => 'Usuario desactivado',
        'user.password_changed' => 'Contrasena cambiada',
        'user.email_changed' => 'Correo cambiado',
        'team.access_changed' => 'Acceso de equipo',
        'brand.moved' => 'Marca movida de cliente',
        'rule_set.published' => 'Reglas publicadas',
        'rule_set.version_created' => 'Nueva version de reglas',
        'prompt_template.published' => 'Instrucciones publicadas',
        'prompt_template.version_created' => 'Nueva version de instrucciones',
        'review.recorded' => 'Revision humana',
        'token.created' => 'Token creado',
        'token.revoked' => 'Token revocado',
        'consumo.exported' => 'Consumo exportado',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('audit.view') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Roles globales ven todo. El resto ve lo de sus marcas y clientes, y lo
     * que hizo el mismo.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();

        if ($user->hasGlobalAccess()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn('brand_id', $user->accessibleBrandIds())
            ->orWhereIn('client_id', $user->fullAccessClientIds())
            ->orWhere('user_id', $user->id));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('action')->label('Accion')
                    ->formatStateUsing(fn (string $state): string => self::ACCIONES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'auth.failed') => 'danger',
                        str_starts_with($state, 'user.'), str_starts_with($state, 'team.') => 'warning',
                        str_contains($state, 'published') => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('user_email')->label('Quien')->searchable()->placeholder('sistema'),
                TextColumn::make('detalle')->label('Detalle')
                    ->state(fn (AuditLog $r): string => self::resumen($r))
                    ->wrap(),
                TextColumn::make('ip_address')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')->label('Accion')->options(self::ACCIONES),
            ])
            ->recordActions([
                Action::make('ver')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (AuditLog $r) => view('filament.modals.bitacora', ['log' => $r])),
            ]);
    }

    private static function resumen(AuditLog $r): string
    {
        $n = (array) ($r->new_values ?? []);
        $partes = [];

        foreach (['version', 'cambio', 'final_verdict', 'token_name', 'email', 'guard', 'alcance'] as $k) {
            if (isset($n[$k]) && is_scalar($n[$k]) && $n[$k] !== '') {
                $partes[] = $k.': '.$n[$k];
            }
        }

        if (isset($n['roles_actuales'])) {
            $partes[] = 'roles: '.implode(', ', (array) $n['roles_actuales']);
        }

        if ($r->auditable_type !== null) {
            $partes[] = class_basename($r->auditable_type).' #'.$r->auditable_id;
        }

        return implode(' · ', $partes);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }
}
