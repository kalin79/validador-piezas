<?php

declare(strict_types=1);

namespace App\Services\Ai;

use InvalidArgumentException;

final class VisionProviderFactory
{
    public static function make(?string $driver = null): VisionProvider
    {
        $driver ??= (string) config('ai.driver', 'fake');

        return match ($driver) {
            'anthropic' => new AnthropicProvider(),
            'fake' => new FakeProvider(),
            default => throw new InvalidArgumentException("Driver de IA desconocido: {$driver}"),
        };
    }
}
