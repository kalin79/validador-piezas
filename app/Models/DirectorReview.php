<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DirectorReviewStatus;
use App\Enums\VerdictStatus;
use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Un envio de una pieza al director y su decision.
 *
 * Mientras esta pendiente solo puede cambiar su estado, una vez, a aprobado,
 * devuelto o retirado. Despues es de solo lectura: una decision del director
 * no se edita, se registra otra sobre una version nueva de la pieza.
 */
class DirectorReview extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $guarded = [];

    /** Columnas que puede escribir la decision. Todo lo demas queda fijo. */
    private const DECISION = ['status', 'decided_by', 'decided_at', 'decision_comment', 'updated_at'];

    protected function casts(): array
    {
        return [
            'status' => DirectorReviewStatus::class,
            'verdict_at_send' => VerdictStatus::class,
            'verdict_from_human' => 'boolean',
            'score_at_send' => 'float',
            'decided_at' => 'datetime',
        ];
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function booted(): void
    {
        static::updating(static function (self $envio): void {
            if ($envio->getOriginal('status') !== DirectorReviewStatus::Pending) {
                throw new LogicException('Un envio ya resuelto no se modifica.');
            }

            $otras = array_diff(array_keys($envio->getDirty()), self::DECISION);

            if ($otras !== []) {
                throw new LogicException('Solo se puede registrar la decision: '.implode(', ', $otras).' es de solo lectura.');
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Los envios al director no se borran: son registro de aprobacion.');
        });
    }

    public function estaPendiente(): bool
    {
        return $this->status === DirectorReviewStatus::Pending;
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function validationRun(): BelongsTo
    {
        return $this->belongsTo(ValidationRun::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
