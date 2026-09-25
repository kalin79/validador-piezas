<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ValidationRun;
use Illuminate\Console\Command;

/**
 * Resumen de una validacion para diagnosticar desde la terminal.
 *
 * Reemplaza a diagnostico-validacion.php y a los comandos de tinker que se
 * usaron durante la auditoria: veredicto, costo, hallazgos con su confianza,
 * reglas pendientes con su motivo y lo que el modelo declaro por regla.
 * Solo lee: no modifica nada ni llama a la API.
 *
 *   php artisan validacion:diagnostico            (la ultima)
 *   php artisan validacion:diagnostico 94
 *   php artisan validacion:diagnostico 94 --regla=COMP-002 --regla=COMP-005
 */
class DiagnosticarValidacion extends Command
{
    protected $signature = 'validacion:diagnostico
                            {id? : Id de la ejecucion. Sin id, la mas reciente.}
                            {--regla=* : Muestra lo que el modelo declaro sobre estas reglas.}';

    protected $description = 'Muestra el detalle de una validacion (solo lectura)';

    public function handle(): int
    {
        $consulta = ValidationRun::withoutGlobalScopes()->with(['verdict', 'findings', 'asset']);
        $run = $this->argument('id') !== null
            ? $consulta->find((int) $this->argument('id'))
            : $consulta->latest('id')->first();

        if ($run === null) {
            $this->error('No existe esa ejecucion.');

            return self::FAILURE;
        }

        $meta = (array) ($run->deterministic_results ?? []);
        $v = $run->verdict;

        $this->line("<options=bold>Ejecucion {$run->id}</> · ".\App\Support\Fecha::local($run->created_at)?->format('d/m/Y H:i:s')
            .' · '.($run->asset?->original_filename ?? '—'));
        $this->line('Estado: '.$run->status->value.' · Veredicto: '.($v?->status->label() ?? '—')
            .' · Puntaje: '.($v?->score ?? '—').' · Formula v'.($v?->scoring_formula_snapshot['formula_version'] ?? '—'));
        $this->line('Modelo: '.($run->model_identifier ?? '—').' · stop_reason: '.($meta['ai_stop_reason'] ?? '—')
            .' · tokens salida: '.($run->output_tokens ?? '—').' · USD '.($run->cost_usd ?? '—'));

        if ($run->error_message) {
            $this->warn('Error: '.$run->error_message);
        }

        $this->newLine();
        $this->line('<options=bold>Hallazgos</>');
        $this->table(
            ['Severidad', 'Regla', 'Origen', 'Conf.', 'Descripcion'],
            $run->findings->map(fn ($f): array => [
                $f->severity->value,
                $f->rule_code ?? '—',
                $f->origin->value,
                $f->evidence_data['confidence'] ?? '—',
                mb_substr($f->description, 0, 90),
            ])->all(),
        );

        $pendientes = (array) ($meta['pending_rules'] ?? []);
        $this->line('<options=bold>Reglas sin verificar: '.count($pendientes).'</>');

        foreach ($pendientes as $codigo => $p) {
            $this->line("  {$codigo} · {$p['outcome']} · ".mb_substr((string) ($p['reason'] ?? ''), 0, 130));
        }

        if (($descartados = (array) ($meta['ai_discarded'] ?? [])) !== []) {
            $this->newLine();
            $this->line('<options=bold>Descartados por el sistema</>');

            foreach ($descartados as $d) {
                $this->line('  '.$d);
            }
        }

        if (($reglas = (array) $this->option('regla')) !== []) {
            $raw = json_decode((string) $run->raw_model_response, true);
            $entrada = collect($raw['content'] ?? [])->firstWhere('type', 'tool_use')['input'] ?? [];

            $this->newLine();
            $this->line('<options=bold>Declaraciones del modelo</>');

            foreach ($reglas as $codigo) {
                $a = collect($entrada['rule_assessments'] ?? [])->firstWhere('rule_code', $codigo);
                $this->line("  {$codigo}: ".json_encode($a, JSON_UNESCAPED_UNICODE));
            }
        }

        return self::SUCCESS;
    }
}
