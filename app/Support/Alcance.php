<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Acota las consultas de los selectores al alcance de quien los usa.
 *
 * Los selectores de Filament validan que lo enviado este entre las opciones
 * que su consulta devuelve. Por eso acotar la consulta no es solo cosmetico:
 * es la validacion del lado del servidor. Un id fuera de alcance que llegue
 * manipulando la peticion falla la validacion y no se guarda.
 *
 * Solo super_admin ve todo. El auditor tiene alcance global de lectura, pero
 * no administra nada, asi que aqui se trata como cualquier otro.
 */
final class Alcance
{
    public static function esSuperAdmin(?User $user): bool
    {
        return $user?->hasRole('super_admin') === true;
    }

    public static function equipos(Builder $q, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        return self::esSuperAdmin($user)
            ? $q
            : $q->whereIn($q->qualifyColumn('id'), $user?->teamIds() ?? []);
    }

    /** Clientes con acceso completo: los unicos que se pueden conceder o usar como dueno. */
    public static function clientesCompletos(Builder $q, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        return self::esSuperAdmin($user)
            ? $q
            : $q->whereIn($q->qualifyColumn('id'), $user?->fullAccessClientIds() ?? []);
    }

    /** Clientes en los que el usuario ve al menos una marca (para filtros de lectura). */
    public static function clientesVisibles(Builder $q, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        return $user?->hasGlobalAccess() === true
            ? $q
            : $q->whereIn($q->qualifyColumn('id'), $user?->accessibleClientIds() ?? []);
    }

    public static function marcas(Builder $q, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        return self::esSuperAdmin($user)
            ? $q
            : $q->whereIn($q->qualifyColumn('id'), $user?->accessibleBrandIds() ?? []);
    }

    /** Marcas para filtros de solo lectura: el auditor si las ve todas. */
    public static function marcasVisibles(Builder $q, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        return $user?->hasGlobalAccess() === true
            ? $q
            : $q->whereIn($q->qualifyColumn('id'), $user?->accessibleBrandIds() ?? []);
    }

    /** Usuarios que comparten al menos un equipo con quien consulta. */
    public static function usuarios(Builder $q, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (self::esSuperAdmin($user)) {
            return $q;
        }

        $equipos = $user?->teamIds() ?? collect();

        return $q->whereHas('teams', fn (Builder $t): Builder => $t->whereIn('teams.id', $equipos));
    }

    /**
     * Regla de validacion para selects multiples de relacion: todo id NUEVO
     * (que el registro no tenia ya) debe estar dentro del alcance.
     *
     * Los ids que el registro ya tenia se toleran para no bloquear la edicion
     * de un equipo o usuario compartido; lo que se impide es conceder acceso
     * nuevo fuera del alcance propio. Es la garantia del lado del servidor:
     * no depende de que la lista de opciones este bien filtrada.
     *
     * @param  Closure(): iterable<int>  $permitidos  ids permitidos para quien edita
     */
    public static function reglaSoloNuevosPermitidos(string $relacion, Closure $permitidos): Closure
    {
        return static fn (?Model $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record, $relacion, $permitidos): void {
            if (self::esSuperAdmin(auth()->user())) {
                return;
            }

            $enviados = collect(is_array($value) ? $value : [$value])->filter(fn ($v) => filled($v))->map(fn ($v) => (int) $v);

            $actuales = $record?->exists === true
                ? $record->{$relacion}()->pluck($record->{$relacion}()->getRelated()->getQualifiedKeyName())->map(fn ($v) => (int) $v)
                : collect();

            $fueraDeAlcance = $enviados
                ->diff($actuales)
                ->diff(collect($permitidos())->map(fn ($v) => (int) $v));

            if ($fueraDeAlcance->isNotEmpty()) {
                $fail('Hay elementos seleccionados fuera de tu alcance.');
            }
        };
    }
}
