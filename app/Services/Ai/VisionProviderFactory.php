<?php

declare(strict_types=1);

namespace App\Services\Ai;

use InvalidArgumentException;

final class VisionProviderFactory
{
    /**
     * Proveedor para un modelo.
     *
     * Con AI_DRIVER=fake siempre devuelve el simulado (solo fuera de
     * produccion). En cualquier otro caso el proveedor sale del modelo: la
     * misma instalacion puede validar una pieza con Luna y la siguiente con
     * Sonnet.
     */
    public static function make(?string $driver = null, ?string $model = null): VisionProvider
    {
        $driver ??= (string) config('ai.driver', 'real');

        // El driver simulado fabrica hallazgos, texto y un logo detectado. En
        // produccion eso son veredictos inventados, asi que se corta de raiz.
        if ($driver === 'fake') {
            if (app()->isProduction()) {
                throw AiException::productionFake();
            }

            return new FakeProvider;
        }

        // Compatibilidad: AI_DRIVER=anthropic u openai fuerzan el proveedor si
        // no se indica modelo. Con modelo, manda el modelo.
        $modelo = $model ?: (string) config('ai.model');
        $proveedor = Proveedor::de($modelo) ?? (in_array($driver, [Proveedor::ANTHROPIC, Proveedor::OPENAI], true) ? $driver : null);

        return match ($proveedor) {
            Proveedor::ANTHROPIC => new AnthropicProvider,
            Proveedor::OPENAI => new OpenAiProvider,
            default => throw new InvalidArgumentException("No hay proveedor de IA para el modelo {$modelo}. Revisa config/ai.php (providers)."),
        };
    }
}
