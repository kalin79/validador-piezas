<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

final class AiException extends RuntimeException
{
    /**
     * Respuesta que el proveedor SI entrego (y cobro) aunque no se pueda usar,
     * por ejemplo una salida cortada por max_tokens. Se conserva para registrar
     * el costo real y la evidencia cruda.
     */
    public ?VisionResponse $respuestaParcial = null;

    public function conRespuesta(VisionResponse $respuesta): self
    {
        $this->respuestaParcial = $respuesta;

        return $this;
    }

    public static function missingApiKey(): self
    {
        return new self(
            'Falta ANTHROPIC_API_KEY en el archivo .env. '
            .'Mientras tanto puedes usar AI_DRIVER=fake para probar el flujo sin gastar tokens.'
        );
    }

    public static function requestFailed(int $status, string $body): self
    {
        return new self("La API respondio {$status}: ".mb_substr($body, 0, 500));
    }

    public static function noToolUse(): self
    {
        return new self(
            'El modelo no devolvio la salida estructurada esperada. '
            .'Nunca se persiste una respuesta que no cumple el esquema.'
        );
    }

    public static function invalidSchema(string $detalle): self
    {
        return new self("La salida del modelo no cumple el esquema: {$detalle}");
    }

    public static function imageTooLarge(int $bytes, int $max): self
    {
        return new self(sprintf(
            'La imagen codificada pesa %.1f MB y el limite es %.1f MB.',
            $bytes / 1048576,
            $max / 1048576,
        ));
    }

    public static function truncated(?string $stopReason): self
    {
        return new self(sprintf(
            'La respuesta del modelo se corto antes de terminar (stop_reason=%s). '
            .'Una lista de hallazgos incompleta no se usa: las reglas de juicio quedan sin evaluar.',
            $stopReason ?? 'desconocido',
        ));
    }

    public static function productionFake(): self
    {
        return new self(
            'AI_DRIVER=fake no esta permitido en produccion: el driver simulado inventa resultados. '
            .'Configura AI_DRIVER=anthropic y ANTHROPIC_API_KEY.'
        );
    }
}
