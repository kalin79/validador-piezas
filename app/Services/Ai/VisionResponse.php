<?php

declare(strict_types=1);

namespace App\Services\Ai;

final readonly class VisionResponse
{
    /**
     * @param  array<string, mixed>  $data  salida estructurada ya validada
     * @param  array<string, mixed>  $raw   respuesta cruda del proveedor, evidencia de auditoria
     */
    public function __construct(
        public array $data,
        public array $raw,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public float $costUsd,
        public bool $simulated = false,
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
