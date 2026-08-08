<?php

declare(strict_types=1);

/**
 * Configuracion del motor de validacion con IA.
 *
 * Precios y modelos verificados contra la documentacion oficial en agosto de
 * 2026. Cambian: conviene revisarlos antes de proyectar costos a un cliente.
 */
return [

    /*
     | Driver activo.
     |
     | 'fake' devuelve respuestas simuladas sin llamar a la API ni gastar
     | tokens. Sirve para probar el flujo completo antes de conectar la clave.
     */
    'driver' => env('AI_DRIVER', 'fake'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',
        'timeout' => (int) env('AI_TIMEOUT', 120),
        'max_retries' => (int) env('AI_MAX_RETRIES', 3),
    ],

    'model' => env('AI_MODEL', 'claude-sonnet-5'),

    'max_tokens' => (int) env('AI_MAX_TOKENS', 4096),

    /*
     * Temperatura de muestreo.
     *
     * Sin valor por defecto a proposito: los modelos de la familia 5 y los
     * Opus 4.7 en adelante eliminaron temperature, top_p y top_k, y enviarlos
     * devuelve 400. El proveedor solo la incluye si esta definida aqui y el
     * modelo la acepta.
     *
     * Definir AI_TEMPERATURE solo si se va a usar un modelo anterior.
     */
    'temperature' => env('AI_TEMPERATURE'),

    /*
     | Precios por millon de tokens, en dolares.
     |
     | Se listan los precios de tarifa, no los promocionales: es preferible
     | que el costo estimado sobrepase al real y no al reves.
     */
    'pricing' => [
        'claude-fable-5' => ['input' => 10.00, 'output' => 50.00],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
        'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00],
        'claude-haiku-4-5-20251001' => ['input' => 1.00, 'output' => 5.00],
    ],

    /*
     | Preparacion de la imagen antes de enviarla.
     |
     | No es solo ahorro: la API rechaza imagenes sobre 10 MB en base64, y una
     | pieza de 4000 px no se evalua mejor que una de 1568. El lado maximo
     | coincide con el limite a partir del cual el modelo redimensiona igual.
     */
    'image' => [
        'max_side' => (int) env('AI_IMAGE_MAX_SIDE', 1568),
        'jpeg_quality' => (int) env('AI_IMAGE_QUALITY', 85),
        'max_base64_bytes' => 10 * 1024 * 1024,
    ],

    /*
     | Clave de la plantilla de prompt que usa el validador.
     | La plantilla concreta y su version viven en la tabla prompt_templates.
     */
    'prompt_key' => 'piece_validation',
];
