<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente de la API de mensajes de Anthropic.
 *
 * La salida estructurada se obtiene declarando una herramienta con su esquema
 * y forzando su uso con tool_choice. Es mas confiable que pedir JSON en el
 * texto: el modelo no puede devolver prosa alrededor ni omitir campos.
 */
final class AnthropicProvider implements VisionProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function analyze(VisionRequest $request): VisionResponse
    {
        $apiKey = config('ai.anthropic.api_key');

        if (blank($apiKey)) {
            throw AiException::missingApiKey();
        }

        $model = $request->model ?: (string) config('ai.model');

        $payload = [
            'model' => $model,
            'max_tokens' => (int) config('ai.max_tokens', 4096),
            'system' => $request->systemPrompt,
            'tools' => [[
                'name' => $request->toolName,
                'description' => 'Registra el resultado completo del analisis de la pieza grafica.',
                'input_schema' => $request->outputSchema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => $request->toolName],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    // La imagen va antes del texto: rinde mejor asi.
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $request->imageMediaType,
                            'data' => $request->imageBase64,
                        ],
                    ],
                    ['type' => 'text', 'text' => $request->userPrompt],
                ],
            ]],
        ];

        // Los modelos de la familia 5 y los Opus 4.7 en adelante eliminaron los
        // parametros de muestreo: enviar temperature, top_p o top_k devuelve un
        // 400 "deprecated for this model". Por eso el parametro se omite salvo
        // que este configurado a proposito, y aun asi solo para modelos que lo
        // aceptan.
        //
        // En los modelos nuevos la consistencia se busca por otra via: reglas
        // con criterios explicitos e instrucciones en el prompt del sistema.
        $temperatura = config('ai.temperature');

        if ($temperatura !== null && ! $this->esModeloSinMuestreo($model)) {
            $payload['temperature'] = (float) $temperatura;
        }

        $respuesta = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => (string) config('ai.anthropic.version'),
            'content-type' => 'application/json',
        ])
            ->timeout((int) config('ai.anthropic.timeout', 120))
            ->retry(
                (int) config('ai.anthropic.max_retries', 3),
                throw: false,
                sleepMilliseconds: fn (int $intento): int => 1000 * (2 ** ($intento - 1)),
                // Se reintenta lo transitorio: red, limite de uso (429),
                // sobrecarga (529) y errores del servidor (5xx). Un 400 o un
                // 401 no se arreglan reintentando.
                when: fn (\Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException
                        && in_array($e->response->status(), [429, 500, 502, 503, 504, 529], true)),
            )
            ->post(rtrim((string) config('ai.anthropic.base_url'), '/').'/v1/messages', $payload);

        if ($respuesta->failed()) {
            Log::warning('Fallo la llamada al modelo de vision', [
                'status' => $respuesta->status(),
                'model' => $model,
            ]);

            throw AiException::requestFailed($respuesta->status(), $respuesta->body());
        }

        $cuerpo = $respuesta->json();
        $stopReason = isset($cuerpo['stop_reason']) ? (string) $cuerpo['stop_reason'] : null;

        // Con tool_choice forzado, una salida completa termina en tool_use.
        // Cualquier otro motivo (max_tokens sobre todo) significa que el JSON
        // de la herramienta puede estar incompleto: menos hallazgos de los
        // reales, que se leerian como una pieza mejor de lo que es.
        $entradaParcial = (int) ($cuerpo['usage']['input_tokens'] ?? 0);
        $salidaParcial = (int) ($cuerpo['usage']['output_tokens'] ?? 0);

        if ($stopReason !== 'tool_use') {
            Log::warning('Respuesta del modelo sin terminar en tool_use', [
                'stop_reason' => $stopReason,
                'model' => $model,
                'output_tokens' => $salidaParcial,
            ]);

            // Anthropic cobra lo generado aunque se descarte: se adjunta la
            // respuesta para que el costo quede registrado.
            throw AiException::truncated($stopReason)->conRespuesta(new VisionResponse(
                data: [],
                raw: $cuerpo,
                model: (string) ($cuerpo['model'] ?? $model),
                inputTokens: $entradaParcial,
                outputTokens: $salidaParcial,
                costUsd: round(TokenEstimator::costUsd($model, $entradaParcial, $salidaParcial), 6),
                stopReason: $stopReason,
            ));
        }

        $bloque = collect($cuerpo['content'] ?? [])
            ->firstWhere('type', 'tool_use');

        if ($bloque === null || ! isset($bloque['input'])) {
            throw AiException::noToolUse();
        }

        $entrada = (int) ($cuerpo['usage']['input_tokens'] ?? 0);
        $salida = (int) ($cuerpo['usage']['output_tokens'] ?? 0);

        return new VisionResponse(
            data: $bloque['input'],
            raw: $cuerpo,
            model: (string) ($cuerpo['model'] ?? $model),
            inputTokens: $entrada,
            outputTokens: $salida,
            costUsd: round(TokenEstimator::costUsd($model, $entrada, $salida), 6),
            stopReason: $stopReason,
        );
    }

    /**
     * Modelos que rechazan los parametros de muestreo.
     *
     * Se detecta por nombre y no por lista cerrada de identificadores: una
     * lista exacta se desactualiza con cada version nueva, y el sintoma seria
     * un 400 en produccion. Ante la duda conviene omitir el parametro, que es
     * el comportamiento seguro en cualquier modelo.
     */
    private function esModeloSinMuestreo(string $model): bool
    {
        foreach (['sonnet-5', 'opus-5', 'haiku-5', 'opus-4-7', 'opus-4-8'] as $familia) {
            if (str_contains($model, $familia)) {
                return true;
            }
        }

        return false;
    }
}
