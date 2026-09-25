<?php

declare(strict_types=1);

namespace App\Services\Ai;

use InvalidArgumentException;

final class VisionProviderFactory
{
    public static function make(?string $driver = null): VisionProvider
    {
        $driver ??= (string) config('ai.driver', 'anthropic');

        // El driver simulado fabrica hallazgos, texto y un logo detectado. En
        // produccion eso son veredictos inventados, asi que se corta de raiz.
        if ($driver === 'fake' && app()->isProduction()) {
            throw AiException::productionFake();
        }

        return match ($driver) {
            'anthropic' => new AnthropicProvider(),
            'fake' => new FakeProvider(),
            default => throw new InvalidArgumentException("Driver de IA desconocido: {$driver}"),
        };
    }
}
