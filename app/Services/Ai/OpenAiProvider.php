<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente de la Responses API de OpenAI (familia GPT-6: Astra, Sol, Luna).
 *
 * La salida estructurada se pide con text.format = json_schema en modo
 * estricto: el modelo no puede devolver prosa ni omitir campos. Es el
 * equivalente del tool_choice forzado que se usa con Anthropic.
 *
 * El modo estricto exige que todos los campos esten en "required" y que los
 * objetos cierren con additionalProperties=false. Los campos que en nuestro
 * esquema son opcionales se declaran como anulables, y al recibir la
 * respuesta los null se quitan: el resto del sistema ve la misma forma que
 * con Claude (campo ausente = no informado).
 *
 * Documentacion: https://developers.openai.com/api/docs/guides/structured-outputs
 */
final class OpenAiProvider implements VisionProvider
{
    public function name(): string
    {
        return Proveedor::OPENAI;
    }

    public function analyze(VisionRequest $request): VisionResponse
    {
        $apiKey = config('ai.openai.api_key');

        if (blank($apiKey)) {
            throw AiException::missingApiKey('OPENAI_API_KEY');
        }

        $model = $request->model ?: (string) config('ai.model');

        $payload = [
            'model' => $model,
            'max_output_tokens' => (int) config('ai.max_tokens', 16000),
            // La evidencia queda en nuestra base; no hace falta que OpenAI
            // guarde la conversacion.
            'store' => false,
            'input' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_image',
                            'image_url' => 'data:'.$request->imageMediaType.';base64,'.$request->imageBase64,
                            // "high": la leyenda legal suele ser texto chico.
                            'detail' => 'high',
                        ],
                        ['type' => 'input_text', 'text' => $request->userPrompt],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $request->toolName,
                    'schema' => self::esquemaEstricto($request->outputSchema),
                    'strict' => true,
                ],
            ],
        ];

        if (filled($esfuerzo = config('ai.openai.reasoning_effort'))) {
            $payload['reasoning'] = ['effort' => (string) $esfuerzo];
        }

        $respuesta = Http::withToken((string) $apiKey)
            ->acceptJson()
            ->timeout((int) config('ai.openai.timeout', 120))
            ->retry(
                (int) config('ai.openai.max_retries', 3),
                throw: false,
                sleepMilliseconds: fn (int $intento): int => 1000 * (2 ** ($intento - 1)),
                // Igual que con Anthropic: se reintenta solo lo transitorio.
                when: fn (\Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException
                        && in_array($e->response->status(), [429, 500, 502, 503, 504], true)),
            )
            ->post(rtrim((string) config('ai.openai.base_url'), '/').'/v1/responses', $payload);

        if ($respuesta->failed()) {
            Log::warning('Fallo la llamada al modelo de vision', [
                'provider' => 'openai',
                'status' => $respuesta->status(),
                'model' => $model,
            ]);

            throw AiException::requestFailed($respuesta->status(), $respuesta->body());
        }

        $cuerpo = (array) $respuesta->json();
        $estado = isset($cuerpo['status']) ? (string) $cuerpo['status'] : null;
        $entrada = (int) ($cuerpo['usage']['input_tokens'] ?? 0);
        // Incluye los tokens de razonamiento: OpenAI los cobra como salida.
        $salida = (int) ($cuerpo['usage']['output_tokens'] ?? 0);

        $construir = fn (array $data, ?string $motivo): VisionResponse => new VisionResponse(
            data: $data,
            raw: $cuerpo,
            model: (string) ($cuerpo['model'] ?? $model),
            inputTokens: $entrada,
            outputTokens: $salida,
            // Se valoriza con el modelo pedido: la respuesta puede traer un
            // identificador con fecha que no esta en la tabla de tarifas.
            costUsd: round(TokenEstimator::costUsd($model, $entrada, $salida), 6),
            stopReason: $motivo,
        );

        // Una respuesta incompleta (max_output_tokens, filtro de contenido)
        // puede traer un JSON cortado: menos hallazgos de los reales.
        if ($estado !== 'completed') {
            $motivo = (string) ($cuerpo['incomplete_details']['reason'] ?? $estado ?? 'desconocido');

            Log::warning('Respuesta de OpenAI sin completar', ['status' => $estado, 'reason' => $motivo, 'model' => $model]);

            throw AiException::truncated($motivo)->conRespuesta($construir([], $motivo));
        }

        $texto = null;

        foreach ((array) ($cuerpo['output'] ?? []) as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue; // p. ej. bloques de razonamiento
            }

            foreach ((array) ($item['content'] ?? []) as $parte) {
                if (($parte['type'] ?? null) === 'refusal') {
                    throw AiException::refused((string) ($parte['refusal'] ?? ''))->conRespuesta($construir([], 'refusal'));
                }

                if (($parte['type'] ?? null) === 'output_text') {
                    $texto = ($texto ?? '').(string) ($parte['text'] ?? '');
                }
            }
        }

        $data = $texto === null ? null : json_decode($texto, true);

        if (! is_array($data)) {
            throw AiException::noToolUse()->conRespuesta($construir([], 'sin_json'));
        }

        return $construir(self::sinNulos($data), 'completed');
    }

    /**
     * Adapta un JSON Schema al modo estricto de OpenAI.
     *
     * @param  array<string, mixed>  $esquema
     * @return array<string, mixed>
     */
    public static function esquemaEstricto(array $esquema): array
    {
        $tipo = $esquema['type'] ?? null;

        if ($tipo === 'object' || isset($esquema['properties'])) {
            $propiedades = (array) ($esquema['properties'] ?? []);
            $requeridos = (array) ($esquema['required'] ?? []);

            foreach ($propiedades as $nombre => $prop) {
                $prop = self::esquemaEstricto((array) $prop);
                $propiedades[$nombre] = in_array($nombre, $requeridos, true) ? $prop : self::anulable($prop);
            }

            $esquema['properties'] = $propiedades === [] ? new \stdClass : $propiedades;
            $esquema['required'] = array_keys($propiedades);
            $esquema['additionalProperties'] = false;
        }

        if (($tipo === 'array') && isset($esquema['items'])) {
            $esquema['items'] = self::esquemaEstricto((array) $esquema['items']);
        }

        return $esquema;
    }

    /**
     * @param  array<string, mixed>  $prop
     * @return array<string, mixed>
     */
    private static function anulable(array $prop): array
    {
        $tipos = (array) ($prop['type'] ?? []);

        if (! in_array('null', $tipos, true)) {
            $tipos[] = 'null';
        }

        $prop['type'] = $tipos;

        if (isset($prop['enum']) && ! in_array(null, $prop['enum'], true)) {
            $prop['enum'][] = null;
        }

        return $prop;
    }

    /**
     * Quita las claves con null (campos opcionales no informados). Los
     * elementos de listas se conservan.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function sinNulos(array $data): array
    {
        $esLista = array_is_list($data);

        foreach ($data as $clave => $valor) {
            if ($valor === null && ! $esLista) {
                unset($data[$clave]);
            } elseif (is_array($valor)) {
                $data[$clave] = self::sinNulos($valor);
            }
        }

        return $data;
    }
}
