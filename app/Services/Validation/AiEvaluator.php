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
     *     extracted_text: string|null
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

        $respuesta = $this->provider->analyze(new VisionRequest(
            systemPrompt: $prompts['system'],
            userPrompt: $prompts['user'],
            imageBase64: $imagen['data'],
            imageMediaType: $imagen['media_type'],
            outputSchema: $template->output_schema ?? ['type' => 'object'],
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

        $hallazgosLogo = is_array($respuesta->data['logo'] ?? null)
            ? $this->logoEvaluator->evaluate($asset, $respuesta->data['logo'], $channel, $reglaActivos)
            : [];

        return [
            'findings' => array_merge($mapeado['findings'], $hallazgosLogo),
            'response' => $respuesta,
            'template' => $template,
            'discarded' => $mapeado['discarded'],
            'extracted_text' => $respuesta->data['extracted_text'] ?? null,
        ];
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
