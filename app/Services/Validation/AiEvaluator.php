<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Models\Asset;
use App\Models\Rule;
use App\Enums\RuleCategory;
use App\Models\PromptTemplate;
use App\Services\Ai\AiException;
use App\Services\Ai\ImagePreparer;
use App\Services\Ai\VisionProvider;
use App\Services\Ai\VisionProviderFactory;
use App\Services\Ai\VisionRequest;
use App\Services\Ai\VisionResponse;
use App\Services\ResolvedRuleSet;
use App\Services\Validation\Evaluators\LogoEvaluator;
use Illuminate\Support\Facades\Storage;

/**
 * Capa de juicio: copy, tono, cumplimiento normativo y ubicacion del logo.
 *
 * Se ejecuta despues del motor determinista y recibe sus hallazgos como
 * contexto, para que el modelo razone sobre ellos en vez de recalcularlos.
 */
final class AiEvaluator
{
    public function __construct(
        private ?VisionProvider $provider = null,
        private PromptBuilder $promptBuilder = new PromptBuilder(),
        private AiFindingMapper $mapper = new AiFindingMapper(),
        private LogoEvaluator $logoEvaluator = new LogoEvaluator(),
        private ImagePreparer $imagePreparer = new ImagePreparer(),
    ) {
        $this->provider ??= VisionProviderFactory::make();
    }

    /**
     * @param  array<int, FindingDraft>  $deterministicFindings
     * @return array{
     *     findings: array<int, FindingDraft>,
     *     response: VisionResponse,
     *     template: PromptTemplate,
     *     discarded: array<int, string>,
     *     coverage: array<string, array{outcome: \App\Enums\RuleOutcome, reason: string|null}>,
     *     extracted_text: string|null,
     *     user_prompt: string
     * }
     */
    public function evaluate(
        Asset $asset,
        ResolvedRuleSet $resolved,
        ?string $channel,
        array $deterministicFindings,
        ?string $model = null,
    ): array {
        $prompts = $this->promptBuilder->build($asset, $resolved, $channel, $deterministicFindings);

        $ruta = Storage::disk($asset->storage_disk)->path($asset->storage_path);
        $imagen = $this->imagePreparer->prepare($ruta);

        $template = $prompts['template'];

        // Sin esquema publicado no hay contrato que validar. Antes se caia en
        // ['type' => 'object'], que acepta cualquier cosa.
        if (! is_array($template->output_schema) || $template->output_schema === []) {
            throw AiException::invalidSchema('la plantilla publicada no tiene output_schema');
        }

        $codigosJuicio = $resolved->judgmentRules()->pluck('code')->values()->all();

        $respuesta = $this->provider->analyze(new VisionRequest(
            systemPrompt: $prompts['system'],
            userPrompt: $prompts['user']."\n\n".self::instruccionDeCobertura($codigosJuicio),
            imageBase64: $imagen['data'],
            imageMediaType: $imagen['media_type'],
            outputSchema: self::conCobertura($template->output_schema, $codigosJuicio),
            imageWidth: $imagen['width'],
            imageHeight: $imagen['height'],
            model: $model,
        ));

        $this->assertSchema($respuesta->data);

        $mapeado = $this->mapper->map($respuesta->data, $resolved);

        // Si el modelo no devolvio el bloque de logo, no se corre el evaluador.
        // Pasarle un arreglo vacio equivale a decirle "no se detecto el logo",
        // y eso produce un hallazgo bloqueante falso: la pieza se rechaza por
        // un dato que nunca se midio.
        /*
         * La regla que ampara los hallazgos de logo.
         *
         * LogoEvaluator mide contra los activos de marca, no contra reglas,
         * pero sus hallazgos necesitan un codigo: uno de ellos es bloqueante y
         * sin regla no se puede defender el rechazo. Se le pasa la primera
         * regla de categoria "activos obligatorios" del conjunto efectivo.
         */
        $reglaActivos = $resolved->rules
            ->first(fn (Rule $r): bool => $r->category === RuleCategory::RequiredAssets);

        $logo = is_array($respuesta->data['logo'] ?? null)
            ? $this->logoEvaluator->evaluateWithCoverage($asset, $respuesta->data['logo'], $channel, $reglaActivos)
            : ['findings' => [], 'undetermined' => null];

        $cobertura = $mapeado['coverage'];

        // Si la medicion del logo no fue concluyente, la regla que la ampara
        // no puede quedar como cumplida aunque el modelo lo haya dicho.
        if ($reglaActivos !== null && $logo['undetermined'] !== null && $logo['findings'] === []) {
            $cobertura[$reglaActivos->code] = [
                'outcome' => \App\Enums\RuleOutcome::NotDeterminable,
                'reason' => $logo['undetermined'],
            ];
        }

        return [
            'findings' => array_merge($mapeado['findings'], $logo['findings']),
            'response' => $respuesta,
            'template' => $template,
            'discarded' => $mapeado['discarded'],
            'coverage' => $cobertura,
            'extracted_text' => $respuesta->data['extracted_text'] ?? null,
            'user_prompt' => $prompts['user'],
        ];
    }

    /**
     * Agrega al esquema de la plantilla el pronunciamiento obligatorio por
     * regla.
     *
     * Vive en codigo y no en la plantilla a proposito: es el contrato del que
     * depende el calculo del veredicto. Si una plantilla nueva lo omitiera, el
     * sistema volveria a leer "lista vacia" como "cumple todo".
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $codigos
     * @return array<string, mixed>
     */
    public static function conCobertura(array $schema, array $codigos): array
    {
        $schema['type'] ??= 'object';
        $schema['properties'] = (array) ($schema['properties'] ?? []);

        $item = [
            'type' => 'object',
            'properties' => [
                'rule_code' => ['type' => 'string', 'description' => 'Codigo exacto de la regla de juicio.'],
                'status' => [
                    'type' => 'string',
                    'enum' => ['cumple', 'incumple', 'no_determinable'],
                    'description' => 'cumple solo si hay base visible en la pieza. Ante cualquier duda, no_determinable.',
                ],
                'evidence' => ['type' => 'string', 'description' => 'Lo que se ve en la pieza que sostiene el estado, o por que no se puede determinar.'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
            'required' => ['rule_code', 'status', 'evidence', 'confidence'],
        ];

        if ($codigos !== []) {
            $item['properties']['rule_code']['enum'] = array_values($codigos);
        }

        $schema['properties']['rule_assessments'] = [
            'type' => 'array',
            'description' => 'Exactamente un elemento por cada regla de juicio listada, cumpla o no.',
            'items' => $item,
        ];

        // La confianza de cada hallazgo tambien se acota en el esquema.
        if (isset($schema['properties']['findings']['items']['properties']['confidence'])) {
            $schema['properties']['findings']['items']['properties']['confidence']['minimum'] = 0;
            $schema['properties']['findings']['items']['properties']['confidence']['maximum'] = 1;
        }

        $schema['required'] = array_values(array_unique(array_merge(
            (array) ($schema['required'] ?? []),
            ['findings', 'rule_assessments'],
        )));

        return $schema;
    }

    /**
     * @param  array<int, string>  $codigos
     */
    public static function instruccionDeCobertura(array $codigos): string
    {
        return "INSTRUCCION DE COBERTURA (obligatoria)\n"
            .'Ademas de los hallazgos, completa rule_assessments con un elemento por cada una de estas reglas: '
            .implode(', ', $codigos).".\n"
            ."- status=cumple solo si puedes ver en la pieza lo que lo demuestra; cita esa evidencia.\n"
            ."- status=incumple exige registrar tambien el hallazgo en findings.\n"
            ."- status=no_determinable si el texto no se lee, falta informacion o no estas seguro. No adivines: es preferible no_determinable a una afirmacion sin base.\n"
            .'- confidence entre 0 y 1.';
    }

    /**
     * Validacion minima de forma. Nunca se persiste una salida que no cumple
     * la estructura esperada: preferimos una ejecucion fallida y visible a un
     * veredicto construido sobre datos incompletos.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertSchema(array $data): void
    {
        // Solo 'findings' es indispensable: sin el no hay evaluacion que
        // registrar y el veredicto se construiria sobre nada.
        if (! array_key_exists('findings', $data)) {
            throw AiException::invalidSchema("falta el campo 'findings'");
        }

        if (! is_array($data['findings'])) {
            throw AiException::invalidSchema("'findings' debe ser un arreglo");
        }

        // rule_assessments puede faltar (la cobertura lo trata como "no se
        // pronuncio"), pero si viene tiene que ser una lista.
        if (array_key_exists('rule_assessments', $data) && ! is_array($data['rule_assessments'])) {
            throw AiException::invalidSchema("'rule_assessments' debe ser un arreglo");
        }

        // 'extracted_text' y 'logo' son opcionales a proposito.
        //
        // Antes eran obligatorios y eso costaba caro: una pieza sin texto
        // legible, o un modelo que omitia el bloque de logo, tumbaba la
        // evaluacion completa de las reglas de juicio. Veintinueve reglas
        // quedaban sin evaluar por un campo ausente que no cambia ningun
        // veredicto.
        //
        // Ausencia y valor vacio no son lo mismo, y aqui la diferencia
        // importa: si 'logo' viene pero mal formado, sigue siendo un error
        // del modelo y se reporta.
        if (array_key_exists('logo', $data)
            && $data['logo'] !== null
            && (! is_array($data['logo']) || ! array_key_exists('detected', $data['logo']))) {
            throw AiException::invalidSchema("'logo' vino en la respuesta pero sin el campo 'detected'");
        }
    }
}
