<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuleOutcome;
use App\Enums\Severity;
use App\Models\Rule;
use App\Services\ResolvedRuleSet;
use App\Services\Validation\AiFindingMapper;
use Tests\TestCase;

/**
 * La desconfianza hacia el modelo, regla por regla. Sin base de datos: las
 * reglas se arman en memoria.
 */
class AiFindingMapperTest extends TestCase
{
    private function regla(int $id, string $code, string $type, string $severity, bool $locked = false): Rule
    {
        $r = new Rule();
        $r->forceFill([
            'id' => $id,
            'code' => $code,
            'type' => $type,
            'category' => 'compliance',
            'severity' => $severity,
            'is_locked' => $locked,
            'title' => $code,
            'statement' => '-',
        ]);

        return $r;
    }

    private function resuelto(Rule ...$reglas): ResolvedRuleSet
    {
        return new ResolvedRuleSet(null, null, collect($reglas), [], 'hash');
    }

    private function hallazgo(string $code, string $severity, float|string|null $confianza): array
    {
        return [
            'rule_code' => $code, 'category' => 'compliance', 'severity' => $severity,
            'description' => 'Incumple.', 'evidence' => 'texto', 'confidence' => $confianza,
        ];
    }

    public function test_descarta_hallazgos_sobre_reglas_deterministas(): void
    {
        $resuelto = $this->resuelto(
            $this->regla(1, 'PAL-501', 'deterministic', 'major'),
            $this->regla(2, 'COMP-001', 'judgment', 'major'),
        );

        $r = (new AiFindingMapper())->map(['findings' => [$this->hallazgo('PAL-501', 'major', 0.9)]], $resuelto);

        $this->assertSame([], $r['findings']);
        $this->assertStringContainsString('determinista', $r['discarded'][0]);
    }

    public function test_regla_no_anulable_conserva_su_severidad_aunque_el_modelo_la_baje(): void
    {
        $resuelto = $this->resuelto($this->regla(1, 'COMP-001', 'judgment', 'blocking', locked: true));

        $r = (new AiFindingMapper())->map(['findings' => [$this->hallazgo('COMP-001', 'info', 0.9)]], $resuelto);

        $this->assertSame(Severity::Blocking, $r['findings'][0]->severity);
    }

    public function test_una_regla_informativa_dudosa_no_sube_de_nivel(): void
    {
        $resuelto = $this->resuelto($this->regla(1, 'TONE-001', 'judgment', 'info'));

        $r = (new AiFindingMapper())->map(['findings' => [$this->hallazgo('TONE-001', 'info', 0.5)]], $resuelto);

        $this->assertSame(Severity::Info, $r['findings'][0]->severity);
    }

    public function test_confianza_fuera_de_rango_no_se_reinterpreta(): void
    {
        $resuelto = $this->resuelto($this->regla(1, 'COMP-001', 'judgment', 'major'));

        $r = (new AiFindingMapper())->map(['findings' => [$this->hallazgo('COMP-001', 'major', 85)]], $resuelto);

        $this->assertSame([], $r['findings']);
        $this->assertSame(RuleOutcome::NotDeterminable, $r['coverage']['COMP-001']['outcome']);
    }

    public function test_hallazgo_de_baja_confianza_deja_la_regla_sin_determinar(): void
    {
        $resuelto = $this->resuelto($this->regla(1, 'COMP-001', 'judgment', 'major'));

        $r = (new AiFindingMapper())->map([
            'findings' => [$this->hallazgo('COMP-001', 'major', 0.2)],
            'rule_assessments' => [['rule_code' => 'COMP-001', 'status' => 'cumple', 'evidence' => '-', 'confidence' => 0.9]],
        ], $resuelto);

        // Aunque despues diga "cumple", sospecho un incumplimiento: no se afirma nada.
        $this->assertSame(RuleOutcome::NotDeterminable, $r['coverage']['COMP-001']['outcome']);
    }

    public function test_cumple_con_confianza_baja_no_cuenta_como_evaluada(): void
    {
        $resuelto = $this->resuelto($this->regla(1, 'COMP-001', 'judgment', 'major'));

        $r = (new AiFindingMapper())->map([
            'findings' => [],
            'rule_assessments' => [['rule_code' => 'COMP-001', 'status' => 'cumple', 'evidence' => '-', 'confidence' => 0.5]],
        ], $resuelto);

        $this->assertSame(RuleOutcome::NotDeterminable, $r['coverage']['COMP-001']['outcome']);
    }

    public function test_incumple_sin_hallazgo_es_incoherente_y_no_se_da_por_cumplida(): void
    {
        $resuelto = $this->resuelto($this->regla(1, 'COMP-001', 'judgment', 'major'));

        $r = (new AiFindingMapper())->map([
            'findings' => [],
            'rule_assessments' => [['rule_code' => 'COMP-001', 'status' => 'incumple', 'evidence' => '-', 'confidence' => 0.9]],
        ], $resuelto);

        $this->assertSame(RuleOutcome::NotDeterminable, $r['coverage']['COMP-001']['outcome']);
    }
}
