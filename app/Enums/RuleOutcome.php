<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Que paso con cada regla en una ejecucion.
 *
 * Existe para cerrar la ambiguedad mas cara del sistema: "no hay hallazgo"
 * puede significar "se midio y cumple" o "nadie la miro". Solo Evaluated
 * permite afirmar cumplimiento; cualquier otro estado obliga a decir que la
 * regla quedo sin verificar.
 */
enum RuleOutcome: string
{
    // Se midio o se juzgo con base suficiente. Si no hay hallazgo, cumple.
    case Evaluated = 'evaluated';

    // Se intento, pero faltan datos para afirmar algo (sin canal, sin paleta,
    // el modelo declaro que no puede determinarlo, confianza insuficiente).
    case NotDeterminable = 'not_determinable';

    // Fallo tecnico: excepcion en un evaluador, error o timeout de la IA.
    case Error = 'error';

    // Ningun motor cubrio la regla (categoria sin evaluador, IA no ejecutada).
    case NotEvaluated = 'not_evaluated';

    public function label(): string
    {
        return match ($this) {
            self::Evaluated => 'Evaluada',
            self::NotDeterminable => 'No determinable',
            self::Error => 'Error al evaluar',
            self::NotEvaluated => 'Sin evaluar',
        };
    }

    /**
     * Prioridad al combinar dos resultados para la misma regla: gana el peor.
     * Un "evaluada" nunca tapa un error.
     */
    public function gravedad(): int
    {
        return match ($this) {
            self::Evaluated => 0,
            self::NotDeterminable => 1,
            self::NotEvaluated => 2,
            self::Error => 3,
        };
    }
}
