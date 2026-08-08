<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\FindingOrigin;
use App\Enums\RuleCategory;
use App\Enums\Severity;

/**
 * Hallazgo antes de persistirse. Se exige evidencia estructurada siempre:
 * un hallazgo sin numeros no se puede defender en una auditoria ni corregir
 * quien diseno la pieza.
 */
final readonly class FindingDraft
{
    /**
     * @param  array<string, mixed>  $evidenceData
     */
    public function __construct(
        public RuleCategory $category,
        public Severity $severity,
        public string $description,
        public ?string $ruleCode = null,
        public ?int $ruleId = null,
        public ?string $evidence = null,
        public array $evidenceData = [],
        public ?string $suggestion = null,
        public FindingOrigin $origin = FindingOrigin::Deterministic,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_code' => $this->ruleCode,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'origin' => $this->origin->value,
            'description' => $this->description,
            'evidence' => $this->evidence,
            'evidence_data' => $this->evidenceData !== [] ? $this->evidenceData : null,
            'suggestion' => $this->suggestion,
            'confidence' => $this->origin === FindingOrigin::Deterministic ? 1.0 : null,
        ];
    }
}
