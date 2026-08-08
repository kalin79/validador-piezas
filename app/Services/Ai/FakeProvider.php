<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Driver simulado. No llama a ninguna API ni gasta tokens.
 *
 * Devuelve una respuesta que cumple el esquema para poder recorrer el flujo
 * completo: construccion del prompt, conversion a hallazgos, calculo del
 * veredicto y registro de costo. Es la forma de verificar que la integracion
 * funciona antes de conectar la clave y empezar a pagar por cada prueba.
 *
 * Los tokens y el costo se calculan con la misma formula que el driver real,
 * asi que las cifras que muestra el panel son representativas.
 */
final class FakeProvider implements VisionProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function analyze(VisionRequest $request): VisionResponse
    {
        $model = $request->model ?: (string) config('ai.model');

        $estimacion = TokenEstimator::estimate(
            model: $model,
            imageWidth: $request->imageWidth ?? 1080,
            imageHeight: $request->imageHeight ?? 1080,
            systemPrompt: $request->systemPrompt,
            userPrompt: $request->userPrompt,
        );

        $data = $this->respuestaSimulada($request);

        return new VisionResponse(
            data: $data,
            raw: [
                'simulated' => true,
                'note' => 'Respuesta generada por el driver simulado. No se llamo a ninguna API.',
                'content' => [['type' => 'tool_use', 'name' => $request->toolName, 'input' => $data]],
                'usage' => [
                    'input_tokens' => $estimacion['input_tokens'],
                    'output_tokens' => $estimacion['output_tokens'],
                ],
            ],
            model: $model,
            inputTokens: $estimacion['input_tokens'],
            outputTokens: $estimacion['output_tokens'],
            costUsd: $estimacion['cost_usd'],
            simulated: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function respuestaSimulada(VisionRequest $request): array
    {
        // Se derivan los codigos de regla del prompt para que los hallazgos
        // simulados referencien reglas que existen de verdad en el conjunto.
        preg_match_all('/\[([A-Z]+-\d+)\]/', $request->userPrompt, $coincidencias);
        $codigos = array_values(array_unique($coincidencias[1] ?? []));

        $hallazgos = [];

        if (isset($codigos[0])) {
            $hallazgos[] = [
                'rule_code' => $codigos[0],
                'category' => 'copy',
                'severity' => 'minor',
                'description' => 'Hallazgo simulado: el driver de IA esta en modo prueba y no analizo la pieza.',
                'evidence' => 'Texto de ejemplo citado de la pieza',
                'suggestion' => 'Configura ANTHROPIC_API_KEY y cambia AI_DRIVER a anthropic para obtener analisis reales.',
                'confidence' => 0.5,
            ];
        }

        return [
            'extracted_text' => 'Texto simulado. El driver de prueba no lee la pieza.',
            'text_blocks' => [],
            'logo' => [
                'detected' => true,
                'confidence' => 0.9,
                'bounding_box' => ['x' => 0.72, 'y' => 0.82, 'width' => 0.20, 'height' => 0.10],
                'notes' => 'Deteccion simulada en la esquina inferior derecha.',
            ],
            'findings' => $hallazgos,
            'overall_notes' => 'Ejecucion con driver simulado. Ningun juicio de esta salida es real.',
        ];
    }
}
