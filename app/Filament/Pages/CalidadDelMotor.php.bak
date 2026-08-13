<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\FindingReviewState;
use App\Models\HumanReview;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Que tan bien juzga el motor, medido contra el criterio humano.
 *
 * Solo cuenta hallazgos revisados. Un hallazgo sin revisar no es evidencia de
 * nada, y contarlo como acierto seria medirse a si mismo.
 *
 * La precision por regla es el numero que importa: dice cual regla esta mal
 * escrita. Una regla con muchos falsos positivos no significa que el modelo
 * sea malo, sino que el enunciado es ambiguo y hay que reescribirlo. Esa es
 * la forma en que este sistema mejora.
 */
class CalidadDelMotor extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Operacion';

    protected static ?string $navigationLabel = 'Calidad del motor';

    protected static ?string $title = 'Calidad del motor';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.calidad-del-motor';

    public int $dias = 90;

    /**
     * @return array<string, mixed>
     */
    public function getResumenProperty(): array
    {
        $marcas = auth()->user()->accessibleBrandIds();
        $desde = now()->subDays($this->dias);

        $revisiones = HumanReview::query()
            ->whereHas('validationRun', fn ($q) => $q->whereIn('brand_id', $marcas))
            ->where('created_at', '>=', $desde)
            ->get();

        $conteos = $this->conteosPorEstado($marcas->all(), $desde);

        $confirmados = $conteos['confirmed'] ?? 0;
        $falsos = $conteos['false_positive'] ?? 0;
        $agregados = $conteos['added_by_human'] ?? 0;

        $juzgados = $confirmados + $falsos;

        return [
            'revisiones' => $revisiones->count(),
            'anulaciones' => $revisiones->where('overrode_machine', true)->count(),
            'confirmados' => $confirmados,
            'falsos' => $falsos,
            'agregados' => $agregados,
            // Precision: de lo que el motor reporto, cuanto era real.
            'precision' => $juzgados > 0 ? round($confirmados / $juzgados * 100, 1) : null,
            // Cobertura aproximada: de todo lo que habia que encontrar,
            // cuanto encontro. Es aproximada porque solo conoce lo que un
            // revisor se tomo el trabajo de agregar.
            'cobertura' => ($confirmados + $agregados) > 0
                ? round($confirmados / ($confirmados + $agregados) * 100, 1)
                : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPorReglaProperty(): array
    {
        $marcas = auth()->user()->accessibleBrandIds();
        $desde = now()->subDays($this->dias);

        $filas = DB::table('findings')
            ->join('validation_runs', 'validation_runs.id', '=', 'findings.validation_run_id')
            ->whereIn('validation_runs.brand_id', $marcas)
            ->where('findings.review_state', '!=', FindingReviewState::Unreviewed->value)
            ->where('findings.created_at', '>=', $desde)
            ->whereNotNull('findings.rule_code')
            ->groupBy('findings.rule_code')
            ->select('findings.rule_code')
            ->selectRaw("SUM(findings.review_state = 'confirmed') as ok")
            ->selectRaw("SUM(findings.review_state = 'false_positive') as fp")
            ->selectRaw("SUM(findings.review_state = 'added_by_human') as escapados")
            ->get();

        $resultado = [];

        foreach ($filas as $f) {
            $ok = (int) $f->ok;
            $fp = (int) $f->fp;
            $escapados = (int) $f->escapados;
            $juzgados = $ok + $fp;

            $resultado[] = [
                'codigo' => $f->rule_code,
                'ok' => $ok,
                'fp' => $fp,
                'escapados' => $escapados,
                'total' => $juzgados,
                'precision' => $juzgados > 0 ? round($ok / $juzgados * 100, 1) : null,
            ];
        }

        // Peor precision primero: es donde hay trabajo por hacer.
        usort($resultado, static function (array $a, array $b): int {
            $pa = $a['precision'] ?? 101;
            $pb = $b['precision'] ?? 101;

            return $pa <=> $pb;
        });

        return $resultado;
    }

    /**
     * @param  array<int, int>  $marcas
     * @return array<string, int>
     */
    private function conteosPorEstado(array $marcas, \DateTimeInterface $desde): array
    {
        return DB::table('findings')
            ->join('validation_runs', 'validation_runs.id', '=', 'findings.validation_run_id')
            ->whereIn('validation_runs.brand_id', $marcas)
            ->where('findings.review_state', '!=', FindingReviewState::Unreviewed->value)
            ->where('findings.created_at', '>=', $desde)
            ->groupBy('findings.review_state')
            ->selectRaw('findings.review_state as estado, count(*) as n')
            ->get()
            ->pluck('n', 'estado')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * Las metricas de precision exponen el desempeno del motor sobre todas
     * las marcas accesibles. Es informacion de auditoria.
     *
     * Las Pages de Filament no pasan por Policy: sin canAccess() quedan
     * abiertas a cualquiera que sepa la URL, aunque el enlace no se vea en el
     * menu.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('audit.view') === true;
    }
}
