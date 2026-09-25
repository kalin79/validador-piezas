<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RuleSetStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptTemplate extends Model
{
    use HasFactory;


    /**
     * Una plantilla publicada o retirada es evidencia: las ejecuciones guardan
     * su id y ese id tiene que seguir significando el mismo texto. Solo puede
     * cambiar de estado (publicar, retirar). Para cambiar el texto se crea una
     * version nueva.
     */
    protected static function booted(): void
    {
        static::updating(static function (self $t): void {
            $original = RuleSetStatus::tryFrom((string) ($t->getRawOriginal('status') ?? ''));

            if ($original === RuleSetStatus::Draft || $original === null) {
                return;
            }

            $prohibidos = array_diff(array_keys($t->getDirty()), ['status', 'published_at', 'updated_at']);

            if ($prohibidos !== []) {
                throw \App\Exceptions\ImmutableRecordException::forUpdate(self::class, array_values($prohibidos));
            }
        });

        static::deleting(static function (self $t): void {
            if (RuleSetStatus::tryFrom((string) ($t->getRawOriginal('status') ?? '')) !== RuleSetStatus::Draft) {
                throw \App\Exceptions\ImmutableRecordException::forDelete(self::class);
            }
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RuleSetStatus::class,
            'output_schema' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', RuleSetStatus::Published->value);
    }

    /**
     * Acota la consulta a un alcance exacto.
     *
     * Se compara con whereNull y no con where(..., null) porque en SQL
     * "client_id = NULL" nunca es verdadero: una instruccion general jamas
     * habria encontrado a sus propias versiones anteriores.
     */
    public function scopeMismoAlcance(Builder $query, ?int $clientId, ?int $brandId): Builder
    {
        return $query
            ->where(fn (Builder $q): Builder => $clientId === null
                ? $q->whereNull('client_id')
                : $q->where('client_id', $clientId))
            ->where(fn (Builder $q): Builder => $brandId === null
                ? $q->whereNull('brand_id')
                : $q->where('brand_id', $brandId));
    }

    /**
     * Etiqueta legible del alcance, para listados y confirmaciones.
     */
    public function alcance(): string
    {
        if ($this->brand_id !== null) {
            return $this->brand?->fullName() ?? 'Marca';
        }

        if ($this->client_id !== null) {
            return ($this->client?->name ?? 'Cliente').' (todas sus marcas)';
        }

        return 'Todos los clientes';
    }

    /**
     * Instruccion vigente para una clave, resuelta en cascada.
     *
     * Precedencia: la de la marca, si no la del cliente, si no la general.
     * Es la misma herencia de los conjuntos de reglas: lo mas especifico gana,
     * y lo general sigue sirviendo a quien no tiene nada propio.
     */
    public static function resolveFor(string $key, ?int $brandId = null, ?int $clientId = null): ?self
    {
        // Si llega la marca pero no el cliente, se deduce: quien llama no
        // tiene por que conocer la jerarquia.
        if ($brandId !== null && $clientId === null) {
            $clientId = Brand::query()->whereKey($brandId)->value('client_id');
        }

        return static::query()
            ->published()
            ->where('key', $key)
            ->where(function (Builder $q) use ($brandId, $clientId): void {
                $q->where(fn (Builder $s): Builder => $s->whereNull('client_id')->whereNull('brand_id'));

                if ($clientId !== null) {
                    $q->orWhere(fn (Builder $s): Builder => $s
                        ->where('client_id', $clientId)
                        ->whereNull('brand_id'));
                }

                if ($brandId !== null) {
                    $q->orWhere('brand_id', $brandId);
                }
            })
            // 0 marca, 1 cliente, 2 general: lo mas especifico primero.
            ->orderByRaw('CASE WHEN brand_id IS NOT NULL THEN 0 WHEN client_id IS NOT NULL THEN 1 ELSE 2 END')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Siguiente numero de version dentro del mismo alcance.
     */
    public static function siguienteVersion(string $key, ?int $clientId, ?int $brandId): int
    {
        return (int) static::query()
            ->where('key', $key)
            ->mismoAlcance($clientId, $brandId)
            ->max('version') + 1;
    }
}
