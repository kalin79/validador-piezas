<?php

declare(strict_types=1);

namespace App\Services\Ai;

final readonly class VisionRequest
{
    /**
     * @param  array<string, mixed>  $outputSchema  esquema JSON que la respuesta debe cumplir
     */
    public function __construct(
        public string $systemPrompt,
        public string $userPrompt,
        public string $imageBase64,
        public string $imageMediaType,
        public array $outputSchema,
        public string $toolName = 'registrar_validacion',
        public ?int $imageWidth = null,
        public ?int $imageHeight = null,
        // Modelo para esta peticion concreta. Null usa el de config/ai.php.
        // Permite revalidar la misma pieza con otro modelo sin tocar el .env.
        public ?string $model = null,
    ) {}
}
