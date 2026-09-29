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

    public static function missingApiKey(string $variable = 'ANTHROPIC_API_KEY'): self
    {
        return new self(
            "Falta {$variable} en el archivo .env. "
            .'Mientras tanto puedes usar AI_DRIVER=fake para probar el flujo sin gastar tokens.'
        );
    }

    /**
     * El modelo se nego a responder (OpenAI lo informa como "refusal"). No
     * es un "cumple": las reglas de juicio quedan sin evaluar.
     */
    public static function refused(string $motivo): self
    {
        return new self('El modelo se nego a evaluar la pieza: '.mb_substr($motivo, 0, 300));
    }

    /**
     * Errores que no se arreglan reintentando: sin saldo, clave invalida,
     * sin permiso. Reintentarlos solo demora el fallo.
     */
    private const PERMANENTES = [
        'insufficient_quota', 'credit_balance_exhausted', 'billing_hard_limit_reached',
        'invalid_api_key', 'authentication_error', 'permission_error', 'model_not_found',
    ];

    /** Que hacer ante cada error conocido, en palabras de quien opera. */
    private const INDICACIONES = [
        'insufficient_quota' => 'La cuenta del proveedor no tiene saldo: carga creditos en su panel de facturacion.',
        'credit_balance_exhausted' => 'La cuenta del proveedor no tiene saldo: carga creditos en su panel de facturacion.',
        'billing_hard_limit_reached' => 'Se alcanzo el limite de gasto de la cuenta: subelo en el panel de facturacion.',
        'invalid_api_key' => 'La clave de API no es valida: revisala en el .env.',
        'authentication_error' => 'La clave de API no es valida: revisala en el .env.',
        'permission_error' => 'La clave no tiene permiso para este modelo.',
        'model_not_found' => 'El modelo no existe o la cuenta no tiene acceso a el.',
        'rate_limit_exceeded' => 'Limite de velocidad del proveedor: se reintento y siguio saturado. Prueba en unos minutos.',
        'rate_limit_error' => 'Limite de velocidad del proveedor: se reintento y siguio saturado. Prueba en unos minutos.',
        'overloaded_error' => 'El proveedor esta saturado. Prueba en unos minutos.',
    ];

    /**
     * Un mensaje de una linea en vez del JSON crudo del proveedor. El cuerpo
     * completo queda en el log; aqui va lo que sirve para actuar.
     */
    public static function requestFailed(int $status, string $body, string $proveedor = 'La API'): self
    {
        $json = json_decode($body, true);
        $error = is_array($json) ? (array) ($json['error'] ?? []) : [];
        $codigo = (string) ($error['code'] ?? '') ?: (string) ($error['type'] ?? '');
        $mensaje = trim((string) ($error['message'] ?? ''));

        if ($mensaje === '') {
            $mensaje = mb_substr(trim(preg_replace('/\s+/', ' ', $body) ?? ''), 0, 200);
        }

        $indicacion = self::INDICACIONES[$codigo] ?? self::INDICACIONES[(string) ($error['type'] ?? '')] ?? null;

        return new self(sprintf(
            '%s respondio %d%s%s%s',
            $proveedor,
            $status,
            $codigo !== '' ? " ({$codigo})" : '',
            $indicacion !== null ? '. '.$indicacion : '',
            $mensaje !== '' ? ' Detalle: '.mb_substr($mensaje, 0, 200) : '',
        ));
    }

    /**
     * Si un error HTTP del proveedor es permanente segun su cuerpo.
     */
    public static function esPermanente(?string $body): bool
    {
        $json = json_decode((string) $body, true);
        $error = is_array($json) ? (array) ($json['error'] ?? []) : [];

        return in_array((string) ($error['code'] ?? ''), self::PERMANENTES, true)
            || in_array((string) ($error['type'] ?? ''), self::PERMANENTES, true);
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
            .'Quita AI_DRIVER=fake y configura la clave del proveedor del modelo elegido.'
        );
    }
}
