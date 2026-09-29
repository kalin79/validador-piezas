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
    'driver' => env('AI_DRIVER', 'real'),

    /*
     | Proveedor segun el modelo. El proveedor NO se elige por separado: sale
     | del prefijo del identificador del modelo. Asi es imposible pedir un
     | modelo de OpenAI y que la llamada vaya a Anthropic, o al reves.
     */
    'providers' => [
        'anthropic' => ['label' => 'Claude', 'prefixes' => ['claude-']],
        'openai' => ['label' => 'OpenAI', 'prefixes' => ['gpt-']],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'timeout' => (int) env('AI_TIMEOUT', 120),
        'max_retries' => (int) env('AI_MAX_RETRIES', 3),
        // Esfuerzo de razonamiento (none, low, medium, high...). Null usa el
        // valor por defecto del modelo. Los tokens de razonamiento se cobran
        // como salida y cuentan contra AI_MAX_TOKENS.
        'reasoning_effort' => env('AI_OPENAI_REASONING'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',
        'timeout' => (int) env('AI_TIMEOUT', 120),
        'max_retries' => (int) env('AI_MAX_RETRIES', 3),
    ],

    /*
     | Modelo por defecto. GPT-6 Luna desde 2026-09-28 por decision del
     | cliente, que quiere probarlo. Es el mas barato de la lista y NO esta
     | calibrado contra las revisiones humanas: comparar su precision en
     | Calidad del motor antes de tomar sus veredictos como definitivos.
     */
    'model' => env('AI_MODEL', 'gpt-6-luna'),

    // Techo, no costo: solo se paga lo que el modelo genera. Con rule_assessments
    // (un pronunciamiento por regla) 4096 se quedaba corto y la respuesta se cortaba.
    'max_tokens' => (int) env('AI_MAX_TOKENS', 16000),

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
    /*
     | Tarifa publica por millon de tokens (USD), sin descuentos de lote ni
     | cache. Verificada contra https://platform.claude.com/docs/en/about-claude/pricing
     | el 2026-09-25. Si Anthropic cambia precios, se actualiza aqui y la fecha.
     |
     | Antes Sonnet 5 figuraba a 3/15: todos los costos registrados con ese
     | modelo quedaron sobreestimados en 50%. El reporte de consumo recalcula
     | con esta tabla a partir de los tokens reales.
     */
    'pricing_verified_at' => '2026-09-28',

    'pricing' => [
        'claude-fable-5' => ['input' => 10.00, 'output' => 50.00],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-haiku-4-5-20251001' => ['input' => 1.00, 'output' => 5.00],
        // OpenAI, tarifa estandar verificada el 2026-09-28 en
        // https://developers.openai.com/api/docs/models (sin cache ni lote).
        'gpt-6-astra' => ['input' => 10.00, 'output' => 50.00],
        'gpt-6-sol' => ['input' => 2.00, 'output' => 10.00],
        'gpt-6-luna' => ['input' => 0.10, 'output' => 0.50],
    ],

    /*
     | Modelos que el usuario puede elegir por ejecucion.
     |
     | La clave es el identificador que se envia a la API; el valor, lo que ve
     | quien elige.
     |
     | El orden y las etiquetas salen de una comparacion real sobre la misma
     | pieza, con el mismo conjunto de reglas y el mismo prompt:
     |
     |   Opus 5   -> 5 hallazgos de juicio, todos legitimos. Entre ellos una
     |               beca anunciada sin vigencia ni condiciones, que es
     |               exposicion normativa.
     |   Haiku 4.5 -> CERO hallazgos de juicio. Los tres que reporto eran
     |               deterministas, o sea producidos por el codigo. La llamada
     |               a la API no aporto nada y la pieza salio aprobada con 95.
     |
     | Por eso Haiku queda marcado como no apto para emitir veredictos. Sirve
     | para comprobar que la tuberia funciona —que la llamada sale, que el
     | esquema calza, que el mapeo no descarta codigos— y cuesta centavos. Para
     | juzgar piezas, no: un modelo que no detecta nada tampoco permite saber
     | si un cambio en el prompt mejoro algo.
     |
     | Un puntaje solo es comparable con otro obtenido con el mismo modelo. El
     | 95 de Haiku y el 40 de Opus describen la misma pieza.
     |
     | Quitar un modelo de aqui lo deshabilita en el panel y en la API sin
     | tocar codigo. Las validaciones historicas que lo usaron conservan su
     | model_identifier: el registro de auditoria no depende de esta lista.
     |
     | Debe mantenerse alineada con 'pricing': un modelo elegible cuyo precio
     | no este listado produce un costo estimado de cero.
     */
    'available_models' => [
        'gpt-6-luna' => 'Luna (OpenAI)  ·  por defecto, en evaluacion',
        'gpt-6-sol' => 'Sol (OpenAI)  ·  equilibrio costo y calidad',
        'gpt-6-astra' => 'Astra (OpenAI)  ·  el mas capaz de OpenAI',
        'claude-sonnet-5' => 'Sonnet 5 (Claude)  ·  produccion hasta 2026-09',
        'claude-opus-5' => 'Opus 5 (Claude)  ·  piezas criticas y promociones',
        'claude-haiku-4-5-20251001' => 'Haiku 4.5 (Claude)  ·  solo pruebas tecnicas, NO emite juicio util',
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
