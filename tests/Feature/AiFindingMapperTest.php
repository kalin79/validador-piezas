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

    public function test_una_sospecha_debil_no_tapa_una_declaracion_firme(): void
    {
        $resuelto = $this->resuelto($this->regla(1, 'COMP-001', 'judgment', 'major'));

        $r = (new AiFindingMapper())->map([
            'findings' => [$this->hallazgo('COMP-001', 'major', 0.2)],
            'rule_assessments' => [['rule_code' => 'COMP-001', 'status' => 'cumple', 'evidence' => '-', 'confidence' => 0.9]],
        ], $resuelto);

        // Caso real (validacion 92, COMP-005): hallazgo a 0.2-0.3 que el propio
        // modelo no sostiene, junto a una declaracion firme por encima del
        // umbral. Manda la declaracion.
        $this->assertSame(RuleOutcome::Evaluated, $r['coverage']['COMP-001']['outcome']);
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

    public function test_un_hallazgo_que_el_modelo_declara_no_determinable_no_resta(): void
    {
        // Caso real (validacion 90): TYPO-004 registrado como hallazgo con el
        // texto "No es posible determinar con certeza...".
        $resuelto = $this->resuelto($this->regla(1, 'TYPO-004', 'judgment', 'minor'));

        $r = (new AiFindingMapper())->map([
            'findings' => [$this->hallazgo('TYPO-004', 'minor', 0.6)],
            'rule_assessments' => [['rule_code' => 'TYPO-004', 'status' => 'no_determinable', 'evidence' => 'No se distingue la tipografia.', 'confidence' => 0.5]],
        ], $resuelto);

        $this->assertSame([], $r['findings']);
        $this->assertSame(RuleOutcome::NotDeterminable, $r['coverage']['TYPO-004']['outcome']);
    }

    public function test_no_aplica_con_motivo_cuenta_como_evaluada(): void
    {
        $resuelto = $this->resuelto($this->regla(1, 'COMP-006', 'judgment', 'major'));

        $r = (new AiFindingMapper())->map([
            'findings' => [],
            'rule_assessments' => [['rule_code' => 'COMP-006', 'status' => 'no_aplica', 'evidence' => 'La pieza no menciona precio ni duracion.', 'confidence' => 0.9]],
        ], $resuelto);

        $this->assertSame(RuleOutcome::Evaluated, $r['coverage']['COMP-006']['outcome']);
        $this->assertStringStartsWith('No aplica:', (string) $r['coverage']['COMP-006']['reason']);
    }

    public function test_no_aplica_sin_motivo_no_se_acepta(): void
    {
        $resuelto = $this->resuelto($this->regla(1, 'COMP-006', 'judgment', 'major'));

        $r = (new AiFindingMapper())->map([
            'findings' => [],
            'rule_assessments' => [['rule_code' => 'COMP-006', 'status' => 'no_aplica', 'evidence' => '', 'confidence' => 0.9]],
        ], $resuelto);

        $this->assertSame(RuleOutcome::NotDeterminable, $r['coverage']['COMP-006']['outcome']);
    }

    public function test_el_umbral_para_cumple_depende_de_la_severidad(): void
    {
        $resuelto = $this->resuelto(
            $this->regla(1, 'TONE-002', 'judgment', 'minor'),
            $this->regla(2, 'COMP-004', 'judgment', 'major'),
            $this->regla(3, 'COMP-009', 'judgment', 'minor', locked: true),
        );

        $cumple = fn (string $c): array => ['rule_code' => $c, 'status' => 'cumple', 'evidence' => 'visible', 'confidence' => 0.55];

        $r = (new AiFindingMapper())->map([
            'findings' => [],
            'rule_assessments' => [$cumple('TONE-002'), $cumple('COMP-004'), $cumple('COMP-009')],
        ], $resuelto);

        $this->assertSame(RuleOutcome::Evaluated, $r['coverage']['TONE-002']['outcome']);        // menor: 0.5 basta
        $this->assertSame(RuleOutcome::NotDeterminable, $r['coverage']['COMP-004']['outcome']); // mayor: exige 0.7
        $this->assertSame(RuleOutcome::NotDeterminable, $r['coverage']['COMP-009']['outcome']); // no anulable: exige 0.7
    }

    public function test_una_sospecha_debil_sin_declaracion_firme_deja_la_regla_pendiente(): void
    {
        $resuelto = $this->resuelto($this->regla(1, 'COMP-002', 'judgment', 'blocking'));

        $r = (new AiFindingMapper())->map([
            'findings' => [$this->hallazgo('COMP-002', 'blocking', 0.3)],
            'rule_assessments' => [['rule_code' => 'COMP-002', 'status' => 'no_determinable', 'evidence' => 'No se puede verificar la cifra.', 'confidence' => 0.4]],
        ], $resuelto);

        $this->assertSame([], $r['findings']);
        $this->assertSame(RuleOutcome::NotDeterminable, $r['coverage']['COMP-002']['outcome']);
    }

    public function test_en_regla_critica_un_incumplimiento_bajo_0_7_va_a_revision_y_no_rechaza(): void
    {
        $resuelto = $this->resuelto(
            $this->regla(1, 'COMP-002', 'judgment', 'blocking'),
            $this->regla(2, 'COMP-010', 'judgment', 'minor', locked: true),
            $this->regla(3, 'COPY-006', 'judgment', 'minor'),
        );

        $r = (new AiFindingMapper())->map(['findings' => [
            $this->hallazgo('COMP-002', 'blocking', 0.65),
            $this->hallazgo('COMP-010', 'minor', 0.65),
            $this->hallazgo('COPY-006', 'minor', 0.65),
        ]], $resuelto);

        $codigos = array_map(fn ($f) => $f->ruleCode, $r['findings']);

        $this->assertSame(['COPY-006'], $codigos); // la menor si cuenta
        $this->assertSame(RuleOutcome::NotDeterminable, $r['coverage']['COMP-002']['outcome']);
        $this->assertSame(RuleOutcome::NotDeterminable, $r['coverage']['COMP-010']['outcome']);
    }

    public function test_en_regla_critica_un_incumplimiento_con_evidencia_solida_si_cuenta(): void
    {
        $resuelto = $this->resuelto($this->regla(1, 'COMP-002', 'judgment', 'blocking'));

        $r = (new AiFindingMapper())->map(['findings' => [$this->hallazgo('COMP-002', 'blocking', 0.85)]], $resuelto);

        $this->assertSame(Severity::Blocking, $r['findings'][0]->severity);
        $this->assertSame(RuleOutcome::Evaluated, $r['coverage']['COMP-002']['outcome']);
    }
}
