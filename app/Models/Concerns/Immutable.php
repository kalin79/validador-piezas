<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;

/**
 * Impide la modificacion y el borrado de registros que son evidencia de auditoria.
 *
 * Hay dos niveles de permiso:
 *
 * - mutableAttributes(): pueden cambiar cuantas veces haga falta. Son campos de
 *   proceso, como el estado o la marca de tiempo de fin.
 *
 * - writeOnceAttributes(): pueden pasar de nulo a un valor UNA sola vez, y
 *   despues quedan congelados. Son campos que no se conocen al crear el
 *   registro pero que una vez escritos son evidencia: con que plantilla de
 *   prompt se evaluo, que modelo respondio, cuantos tokens costo.
 *
 * La distincion importa. Meterlos todos en mutableAttributes() habria resuelto
 * el error igual, pero dejaria abierta la puerta a reescribir despues con que
 * modelo se juzgo una pieza, que es justo lo que este sistema promete que no
 * puede pasar.
 *
 * Proteccion a nivel de aplicacion. No sustituye a los permisos de base de datos:
 * alguien con acceso directo a MySQL puede saltarsela. Ver la nota de produccion
 * en la guia de ejecucion.
 */
trait Immutable
{
    protected static function bootImmutable(): void
    {
        static::updating(static function ($model): bool {
            $bloqueados = $model->disallowedChanges();

            if ($bloqueados === []) {
                return true;
            }

            throw ImmutableRecordException::forUpdate(static::class, $bloqueados);
        });

        static::deleting(static function ($model): bool {
            throw ImmutableRecordException::forDelete(static::class);
        });
    }

    /**
     * Atributos sucios que este registro no admite cambiar.
     *
     * @return array<int, string>
     */
    protected function disallowedChanges(): array
    {
        $mutables = $this->mutableAttributes();
        $unaVez = $this->writeOnceAttributes();

        $bloqueados = [];

        foreach (array_keys($this->getDirty()) as $atributo) {
            if (in_array($atributo, $mutables, true)) {
                continue;
            }

            // Se compara contra el valor crudo de la base y no contra el
            // atributo casteado: un decimal o un json ya convertidos pueden
            // no ser identicos a null aunque la columna lo este.
            if (in_array($atributo, $unaVez, true) && $this->getRawOriginal($atributo) === null) {
                continue;
            }

            $bloqueados[] = $atributo;
        }

        return $bloqueados;
    }

    /**
     * @param  array<int, string>  $attributes
     */
    protected function allowsMutationOf(array $attributes): bool
    {
        return array_diff($attributes, $this->mutableAttributes()) === [];
    }

    /**
     * Atributos que pueden cambiar libremente despues de creado el registro.
     *
     * @return array<int, string>
     */
    protected function mutableAttributes(): array
    {
        return [];
    }

    /**
     * Atributos que admiten una unica escritura, de nulo a un valor.
     *
     * @return array<int, string>
     */
    protected function writeOnceAttributes(): array
    {
        return [];
    }
}
