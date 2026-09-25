<?php

declare(strict_types=1);

namespace App\Services\Consumo;

use App\Models\Brand;
use App\Models\ValidationRun;
use App\Services\Ai\TokenEstimator;
use App\Support\Fecha;
use Carbon\CarbonImmutable;

/**
 * Consumo de IA por cliente en un periodo.
 *
 * De donde sale cada numero:
 *
 * - Tokens: los que devolvio la API de Anthropic en cada respuesta (campo
 *   usage). Son exactos; incluyen los tokens de la imagen.
 * - Costo: tokens reales x tarifa publica de config/ai.php, por modelo. Es
 *   exacto si la cuenta no tiene descuentos (lote, cache, acuerdos). La
 *   factura de Anthropic es la fuente oficial.
 *
 * Se recalcula el costo en vez de sumar cost_usd guardado porque ese valor se
 * congelo con la tarifa del momento, y hasta 2026-09-25 Sonnet 5 estaba
 * configurado a 3/15 en lugar de 2/10. Se informan los dos para transparencia.
 *
 * Quedan fuera las ejecuciones del driver simulado (no llamaron a la API) y
 * las que no registraron tokens.
 */
final class ReporteDeConsumo
{
    /**
     * @param  array<int, int>  $marcasPermitidas  alcance de quien consulta
     * @param  array<int, int>|null  $clientes  filtro opcional por cliente
     * @return array{
     *     desde: CarbonImmutable, hasta: CarbonImmutable,
     *     clientes: array<int, array<string, mixed>>,
     *     total: array<string, mixed>,
     *     modelos_sin_tarifa: array<int, string>
     * }
     */
    public function generar(CarbonImmutable $desde, CarbonImmutable $hasta, array $marcasPermitidas, ?array $clientes = null): array
    {
        // El usuario elige fechas en su zona; la base guarda en UTC.
        $zona = Fecha::zona();
        $inicioUtc = $desde->setTimezone($zona)->startOfDay()->utc();
        $finUtc = $hasta->setTimezone($zona)->endOfDay()->utc();

        $marcas = Brand::query()
            ->whereIn('id', $marcasPermitidas)
            ->when($clientes !== null && $clientes !== [], fn ($q) => $q->whereIn('client_id', $clientes))
            ->with('client:id,name')
            ->get(['id', 'name', 'client_id'])
            ->keyBy('id');

        $filas = ValidationRun::withoutGlobalScopes()
            ->whereIn('brand_id', $marcas->keys())
            ->whereBetween('created_at', [$inicioUtc, $finUtc])
            ->where(fn ($q) => $q->where('input_tokens', '>', 0)->orWhere('output_tokens', '>', 0))
            ->where(fn ($q) => $q->whereNull('deterministic_results->ai_simulated')
                ->orWhere('deterministic_results->ai_simulated', false))
            ->selectRaw('brand_id, model_identifier, COUNT(*) as llamadas, SUM(input_tokens) as entrada, SUM(output_tokens) as salida, SUM(cost_usd) as registrado')
            ->groupBy('brand_id', 'model_identifier')
            ->get();

        $sinTarifa = [];
        $porCliente = [];

        foreach ($filas as $f) {
            $marca = $marcas->get($f->brand_id);

            if ($marca === null) {
                continue;
            }

            $modelo = (string) ($f->model_identifier ?? 'desconocido');
            $entrada = (int) $f->entrada;
            $salida = (int) $f->salida;
            $tarifa = $this->tarifa($modelo);

            if ($tarifa === null) {
                $sinTarifa[$modelo] = true;
            }

            $costo = $tarifa === null ? null : TokenEstimator::costUsd($tarifa, $entrada, $salida);

            $clienteId = (int) $marca->client_id;
            $porCliente[$clienteId] ??= [
                'client_id' => $clienteId,
                'cliente' => $marca->client?->name ?? '—',
                'marcas' => [],
                'modelos' => [],
            ];

            $c = &$porCliente[$clienteId];
            $this->sumar($c['marcas'][$marca->id], ['nombre' => $marca->name], (int) $f->llamadas, $entrada, $salida, $costo, (float) $f->registrado);
            $this->sumar($c['modelos'][$modelo], ['nombre' => $modelo], (int) $f->llamadas, $entrada, $salida, $costo, (float) $f->registrado);
            $this->sumar($c, [], (int) $f->llamadas, $entrada, $salida, $costo, (float) $f->registrado);
            unset($c);
        }

        $clientesOrdenados = collect($porCliente)
            ->map(function (array $c): array {
                $c['marcas'] = collect($c['marcas'])->map(fn (array $m): array => $this->cerrar($m))->sortByDesc('costo')->values()->all();
                $c['modelos'] = collect($c['modelos'])->map(fn (array $m): array => $this->cerrar($m))->sortByDesc('costo')->values()->all();

                return $this->cerrar($c);
            })
            ->sortByDesc('costo')
            ->values()
            ->all();

        $total = null;
        $this->sumar($total, [], 0, 0, 0, 0.0, 0.0);
        $total['con_tarifa'] = false;

        foreach ($clientesOrdenados as $c) {
            $this->sumar($total, [], $c['llamadas'], $c['entrada'], $c['salida'], $c['costo'], $c['registrado']);
            $total['costo_incompleto'] = $total['costo_incompleto'] || $c['costo_incompleto'];
        }

        $total = $this->cerrar($total);

        return [
            'desde' => $inicioUtc->setTimezone($zona),
            'hasta' => $finUtc->setTimezone($zona),
            'clientes' => $clientesOrdenados,
            'total' => $total,
            'modelos_sin_tarifa' => array_keys($sinTarifa),
        ];
    }

    /**
     * Modelo tal como lo devuelve la API (puede traer sufijo de fecha) ->
     * clave de tarifa. Null si no hay tarifa: se informa, no se inventa.
     */
    private function tarifa(string $modelo): ?string
    {
        $precios = (array) config('ai.pricing', []);

        if (isset($precios[$modelo])) {
            return $modelo;
        }

        foreach (array_keys($precios) as $clave) {
            if (str_starts_with($modelo, $clave)) {
                return $clave;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $acumulado
     * @param  array<string, mixed>  $base
     */
    private function sumar(?array &$acumulado, array $base, int $llamadas, int $entrada, int $salida, ?float $costo, float $registrado): void
    {
        // Un acumulado puede llegar con solo sus datos descriptivos (cliente,
        // nombre): se completan los contadores que falten.
        $acumulado = ($acumulado ?? []) + $base + ['llamadas' => 0, 'entrada' => 0, 'salida' => 0, 'costo' => 0.0, 'registrado' => 0.0, 'costo_incompleto' => false, 'con_tarifa' => false];
        $acumulado['llamadas'] = ($acumulado['llamadas'] ?? 0) + $llamadas;
        $acumulado['entrada'] = ($acumulado['entrada'] ?? 0) + $entrada;
        $acumulado['salida'] = ($acumulado['salida'] ?? 0) + $salida;
        $acumulado['registrado'] = ($acumulado['registrado'] ?? 0.0) + $registrado;

        if ($costo === null) {
            $acumulado['costo_incompleto'] = true;
        } else {
            $acumulado['costo'] = ($acumulado['costo'] ?? 0.0) + $costo;
            $acumulado['con_tarifa'] = true;
        }
    }

    /**
     * Sin ninguna llamada con tarifa, el costo es desconocido (null), no cero.
     * El promedio solo se da si el costo esta completo: dividir un costo
     * parcial entre todas las llamadas lo subestimaria.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function cerrar(array $a): array
    {
        if ($a['llamadas'] > 0 && ! $a['con_tarifa']) {
            $a['costo'] = null;
        }

        $a['promedio'] = $a['llamadas'] > 0 && $a['costo'] !== null && ! $a['costo_incompleto']
            ? $a['costo'] / $a['llamadas']
            : null;
        unset($a['con_tarifa']);

        return $a;
    }
}
