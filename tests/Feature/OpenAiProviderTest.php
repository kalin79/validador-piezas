<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Ai\AiException;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\FakeProvider;
use App\Services\Ai\OpenAiProvider;
use App\Services\Ai\Proveedor;
use App\Services\Ai\VisionProviderFactory;
use App\Services\Ai\VisionRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Proveedor OpenAI contra respuestas simuladas de la Responses API. No llama
 * a la API real.
 */
class OpenAiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.driver' => 'real',
            'ai.openai.api_key' => 'sk-prueba',
            'ai.openai.max_retries' => 1,
            'ai.pricing.gpt-6-luna' => ['input' => 0.10, 'output' => 0.50],
        ]);
    }

    private function peticion(?string $modelo = 'gpt-6-luna'): VisionRequest
    {
        return new VisionRequest(
            systemPrompt: 'sistema',
            userPrompt: 'usuario',
            imageBase64: base64_encode('png'),
            imageMediaType: 'image/png',
            outputSchema: [
                'type' => 'object',
                'properties' => [
                    'extracted_text' => ['type' => 'string'],
                    'logo' => [
                        'type' => 'object',
                        'properties' => ['detected' => ['type' => 'boolean'], 'notes' => ['type' => 'string']],
                        'required' => ['detected'],
                    ],
                    'findings' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'rule_code' => ['type' => 'string'],
                            'severity' => ['type' => 'string', 'enum' => ['blocking', 'major']],
                            'suggestion' => ['type' => 'string'],
                        ],
                        'required' => ['rule_code', 'severity'],
                    ]],
                ],
                'required' => ['findings'],
            ],
            model: $modelo,
        );
    }

    /** @param  array<string, mixed>  $data */
    private function respuestaOk(array $data, int $in = 20_000, int $out = 3_000): array
    {
        return [
            'id' => 'resp_1', 'model' => 'gpt-6-luna', 'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]],
            ],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
        ];
    }

    public function test_envia_imagen_esquema_estricto_y_calcula_costo(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->respuestaOk([
            'extracted_text' => null,
            'logo' => ['detected' => true, 'notes' => null],
            'findings' => [['rule_code' => 'COMP-001', 'severity' => 'major', 'suggestion' => null]],
        ]))]);

        $r = (new OpenAiProvider)->analyze($this->peticion());

        // Los null de campos opcionales se quitan: misma forma que con Claude.
        $this->assertArrayNotHasKey('extracted_text', $r->data);
        $this->assertSame(['detected' => true], $r->data['logo']);
        $this->assertSame([['rule_code' => 'COMP-001', 'severity' => 'major']], $r->data['findings']);
        $this->assertSame(20_000, $r->inputTokens);
        $this->assertEqualsWithDelta(0.002 + 0.0015, $r->costUsd, 1e-9);

        Http::assertSent(function (Request $req): bool {
            $b = $req->data();
            $esquema = $b['text']['format']['schema'];

            return $req->url() === 'https://api.openai.com/v1/responses'
                && $req->hasHeader('Authorization', 'Bearer sk-prueba')
                && $b['model'] === 'gpt-6-luna'
                && $b['text']['format']['strict'] === true
                && $b['input'][1]['content'][0]['type'] === 'input_image'
                && str_starts_with($b['input'][1]['content'][0]['image_url'], 'data:image/png;base64,')
                // Estricto: todo requerido, opcionales anulables, sin propiedades extra.
                && $esquema['additionalProperties'] === false
                && $esquema['required'] === ['extracted_text', 'logo', 'findings']
                && $esquema['properties']['extracted_text']['type'] === ['string', 'null']
                && $esquema['properties']['findings']['type'] === 'array'
                && $esquema['properties']['findings']['items']['properties']['suggestion']['type'] === ['string', 'null']
                && $esquema['properties']['findings']['items']['properties']['severity']['type'] === 'string';
        });
    }

    public function test_respuesta_incompleta_se_descarta_pero_registra_costo(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-6-luna', 'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [], 'usage' => ['input_tokens' => 10_000, 'output_tokens' => 16_000],
        ])]);

        try {
            (new OpenAiProvider)->analyze($this->peticion());
            $this->fail('Deberia lanzar');
        } catch (AiException $e) {
            $this->assertStringContainsString('max_output_tokens', $e->getMessage());
            $this->assertSame(16_000, $e->respuestaParcial->outputTokens);
            $this->assertEqualsWithDelta(0.001 + 0.008, $e->respuestaParcial->costUsd, 1e-9);
        }
    }

    public function test_una_negativa_no_se_lee_como_cumplimiento(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-6-luna', 'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No puedo ayudar con eso.']]]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
        ])]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('se nego a evaluar');
        (new OpenAiProvider)->analyze($this->peticion());
    }

    public function test_sin_clave_falla_con_mensaje_claro(): void
    {
        config(['ai.openai.api_key' => null]);

        $this->expectExceptionMessage('Falta OPENAI_API_KEY');
        (new OpenAiProvider)->analyze($this->peticion());
    }

    public function test_el_proveedor_sale_del_modelo(): void
    {
        $this->assertSame(Proveedor::OPENAI, Proveedor::de('gpt-6-luna'));
        $this->assertSame(Proveedor::ANTHROPIC, Proveedor::de('claude-sonnet-5'));
        $this->assertNull(Proveedor::de('modelo-raro'));

        $this->assertInstanceOf(OpenAiProvider::class, VisionProviderFactory::make(model: 'gpt-6-sol'));
        $this->assertInstanceOf(AnthropicProvider::class, VisionProviderFactory::make(model: 'claude-opus-5'));
        $this->assertInstanceOf(FakeProvider::class, VisionProviderFactory::make('fake', 'gpt-6-luna'));

        $this->expectException(InvalidArgumentException::class);
        VisionProviderFactory::make(model: 'modelo-raro');
    }

    public function test_luna_es_el_modelo_por_defecto(): void
    {
        // Se lee el valor por defecto del archivo y no config('ai.model'),
        // que puede venir cambiado por el .env de cada maquina.
        $this->assertStringContainsString("env('AI_MODEL', 'gpt-6-luna')", (string) file_get_contents(config_path('ai.php')));
        $this->assertSame('gpt-6-luna', array_key_first(config('ai.available_models')));

        config(['ai.model' => 'gpt-6-luna']);
        $this->assertInstanceOf(OpenAiProvider::class, VisionProviderFactory::make());
    }
}
