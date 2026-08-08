<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Severity;
use App\Models\Brand;
use App\Models\ValidationRun;
use App\Services\QuickValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Puerta de entrada para clientes externos: plugin de Figma y similares.
 *
 * Produce los mismos registros que el panel. La respuesta incluye tanto los
 * hallazgos como las reglas que se evaluaron sin encontrar nada, porque la
 * ausencia de hallazgo por si sola es ambigua: no distingue "cumple" de
 * "no se evaluo".
 */
final class ValidationController
{
    public function store(Request $request, QuickValidation $servicio): JsonResponse
    {
        $datos = $request->validate([
            'image' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:20480'],
            'brand' => ['required', 'string', 'max:191'],
            'channel' => ['nullable', 'string', 'max:60'],
            'external_ref' => ['nullable', 'string', 'max:191'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        $usuario = $request->user();

        try {
            $brand = QuickValidation::resolveBrand(
                $datos['brand'],
                $usuario->accessibleBrandIds()->all(),
            );
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['brand' => $e->getMessage()]);
        }

        // Solo se admiten modelos del catalogo. Sin esta comprobacion, quien
        // tenga el token podria pedir cualquier identificador y el costo de la
        // llamada seria impredecible.
        if (filled($datos['model'] ?? null)
            && ! array_key_exists($datos['model'], (array) config('ai.available_models', []))) {
            throw ValidationException::withMessages([
                'model' => 'Modelo no disponible. Validos: '
                    .implode(', ', array_keys((array) config('ai.available_models', []))),
            ]);
        }

        try {
            $run = $servicio->validate(
                brand: $brand,
                file: $request->file('image'),
                userId: $usuario->id,
                source: $request->header('X-Origen', 'api'),
                channel: $datos['channel'] ?? null,
                externalRef: $datos['external_ref'] ?? null,
                model: $datos['model'] ?? null,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'validation_failed',
                'message' => 'No se pudo completar la validacion.',
                'detail' => $e->getMessage(),
            ], 500);
        }

        return response()->json($this->serializar($run, $brand), 201);
    }

    /**
     * Consulta una ejecucion ya hecha. Util para revisar sin volver a gastar.
     */
    public function show(Request $request, string $publicId): JsonResponse
    {
        $run = ValidationRun::query()
            ->where('public_id', $publicId)
            ->with(['verdict', 'findings', 'brand.client', 'asset'])
            ->first();

        if ($run === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        if (! in_array($run->brand_id, $request->user()->accessibleBrandIds()->all(), true)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return response()->json($this->serializar($run, $run->brand));
    }

    /**
     * Marcas visibles para el token. El plugin la usa para poblar su selector
     * en vez de traer la lista escrita a mano.
     */
    /**
     * Quien soy y que puedo hacer.
     *
     * El plugin la llama al arrancar para tres cosas: comprobar que el token
     * sigue vivo, saludar por nombre, y decidir si muestra la pantalla de
     * acceso o el formulario. Sin esta ruta, el plugin no puede distinguir
     * "token invalido" de "servidor caido" hasta que el disenador intenta
     * validar y falla.
     *
     * No devuelve nada sensible: nombre, correo y el numero de marcas. El
     * detalle de cuales sale de /marcas, que ya filtra por alcance.
     */
    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json([
            'data' => [
                'name' => $usuario->name,
                'email' => $usuario->email,
                'brands' => $usuario->accessibleBrandCount(),
                'can_validate' => $usuario->hasPermissionTo('validation.trigger'),
                'token_name' => $usuario->currentAccessToken()?->name,
            ],
        ]);
    }

    public function brands(Request $request): JsonResponse
    {
        $marcas = Brand::query()
            ->whereIn('id', $request->user()->accessibleBrandIds())
            ->where('is_active', true)
            ->with('client')
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $b): array => [
                'ref' => $b->client->slug.'/'.$b->slug,
                'client' => $b->client->name,
                'brand' => $b->name,
            ]);

        return response()->json(['data' => $marcas]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(ValidationRun $run, Brand $brand): array
    {
        $run->loadMissing(['verdict', 'findings', 'asset']);

        $meta = (array) ($run->deterministic_results ?? []);
        $veredicto = $run->verdict;

        $conHallazgo = $run->findings->pluck('rule_id')->filter()->unique()->flip();
        $iaCorrio = ($meta['ai_ran'] ?? false) === true;
        $iaSimulada = ($meta['ai_simulated'] ?? false) === true;

        $cumplidas = [];
        $sinEvaluar = [];

        foreach (($run->resolved_rules_snapshot ?? []) as $r) {
            if ($conHallazgo->has($r['rule_id'] ?? null)) {
                continue;
            }

            $determinista = ($r['type'] ?? '') === 'deterministic';

            if ($determinista || ($iaCorrio && ! $iaSimulada)) {
                $cumplidas[] = $r['code'] ?? '?';

                continue;
            }

            $sinEvaluar[] = $r['code'] ?? '?';
        }

        return [
            'id' => $run->public_id,
            'verdict' => $veredicto?->status->value,
            'verdict_label' => $veredicto?->status->label(),
            'score' => $veredicto?->score !== null ? round((float) $veredicto->score, 1) : null,
            'passed' => $veredicto?->status->value === 'approved',

            'brand' => [
                'ref' => $brand->client->slug.'/'.$brand->slug,
                'client' => $brand->client->name,
                'brand' => $brand->name,
            ],

            'rules' => [
                'applied' => count($run->resolved_rules_snapshot ?? []),
                'failed' => $conHallazgo->count(),
                'passed' => count($cumplidas),
                'not_evaluated' => count($sinEvaluar),
                'not_evaluated_codes' => $sinEvaluar,
                'passed_codes' => $cumplidas,
            ],

            'findings' => $run->findings
                ->sortBy(fn ($f): int => match ($f->severity) {
                    Severity::Blocking => 0,
                    Severity::Major => 1,
                    Severity::Minor => 2,
                    default => 3,
                })
                ->values()
                ->map(fn ($f): array => [
                    'severity' => $f->severity->value,
                    'severity_label' => $f->severity->label(),
                    'blocking' => $f->severity === Severity::Blocking,
                    'rule_code' => $f->rule_code,
                    'category' => $f->category->value,
                    'category_label' => $f->category->label(),
                    'origin' => $f->origin->value,
                    'description' => $f->description,
                    'evidence' => $f->evidence,
                    'suggestion' => $f->suggestion,
                    'data' => $f->evidence_data,
                ]),

            'asset' => [
                'filename' => $run->asset?->original_filename,
                'width' => $run->asset?->width,
                'height' => $run->asset?->height,
                'sha256' => $run->asset?->file_hash,
            ],

            'audit' => [
                'rules_hash' => $run->resolution_hash,
                'model' => $run->model_identifier,
                'ai_evaluated' => $iaCorrio && ! $iaSimulada,
                'ai_simulated' => $iaSimulada,
                'cost_usd' => $run->cost_usd !== null ? (float) $run->cost_usd : null,
                'channel' => $run->asset?->submission?->channel,
                'format_checked' => $run->asset?->submission?->channel !== null,
                'evaluated_at' => $run->created_at?->toIso8601String(),
                'panel_url' => $run->asset?->submission
                    ? url('/admin/submissions/'.$run->asset->submission->public_id.'/edit')
                    : null,
            ],
        ];
    }
}
