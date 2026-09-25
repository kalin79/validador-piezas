<?php

declare(strict_types=1);

namespace App\Services\Director;

use App\Enums\DirectorReviewStatus;
use App\Enums\ValidationStatus;
use App\Enums\VerdictStatus;
use App\Models\Asset;
use App\Models\DirectorReview;
use App\Models\User;
use App\Models\ValidationRun;
use App\Notifications\DecisionDelDirector;
use App\Notifications\PiezaEnviadaAlDirector;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * El flujo completo del director: enviar, aprobar, devolver y retirar.
 *
 * Todas las reglas viven aqui y no en las pantallas, para que el panel, la API
 * o un comando futuro no puedan saltarse ninguna. Cada operacion bloquea la
 * fila que toca dentro de una transaccion (como Publicacion y ReviewRecorder):
 * dos clics simultaneos no producen dos envios ni dos decisiones.
 */
final class EnvioAlDirector
{
    public const COMENTARIO_MINIMO = 10;

    public function __construct(private readonly AuditLogger $auditoria) {}

    /**
     * Veredicto que cuenta para una ejecucion: el de la revision humana si
     * existe, si no el de la maquina.
     *
     * @return array{0: VerdictStatus|null, 1: bool} [veredicto, viene de humano]
     */
    public static function veredictoEfectivo(ValidationRun $run): array
    {
        $humano = $run->relationLoaded('humanReviews')
            ? $run->humanReviews->first()
            : $run->humanReviews()->first();

        if ($humano !== null) {
            return [$humano->final_verdict, true];
        }

        return [$run->verdict?->status, false];
    }

    /**
     * Directores que reciben el aviso: usuarios activos con el rol director y
     * acceso a la marca por sus equipos.
     *
     * Se toma el rol y no el permiso a proposito: super_admin tambien tiene
     * director.decide y puede decidir, pero no deberia recibir un aviso por
     * cada pieza de cada cliente.
     *
     * @return Collection<int, User>
     */
    public function directoresDe(int $brandId): Collection
    {
        // Antes de correr el seeder el rol no existe y role() lanza excepcion.
        if (! Role::query()->where('name', 'director')->exists()) {
            return collect();
        }

        return User::role('director')
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $u): bool => $u->accessibleBrandIds()->contains($brandId))
            ->values();
    }

    /**
     * Motivo por el que esta persona no puede enviar esta pieza, o null si
     * puede. Las pantallas lo muestran tal cual.
     */
    public function motivoParaNoEnviar(Asset $asset, User $user): ?string
    {
        if (! $user->hasPermissionTo('director.send')) {
            return 'Tu rol no puede enviar piezas al director.';
        }

        if (! $user->hasGlobalAccess() && ! $user->accessibleBrandIds()->contains((int) $asset->brand_id)) {
            return 'No tienes acceso a esta marca.';
        }

        $run = $asset->latestRun()->with(['verdict', 'humanReviews'])->first();

        if ($run === null) {
            return 'La pieza todavia no se valida.';
        }

        if ($run->status !== ValidationStatus::Completed) {
            return 'La ultima validacion no termino ('.$run->status->label().').';
        }

        [$veredicto] = self::veredictoEfectivo($run);

        if ($veredicto === null || ! $veredicto->habilitaEnvio()) {
            return 'Solo se envia una pieza aprobada. Veredicto actual: '
                .($veredicto?->label() ?? 'sin veredicto')
                .($veredicto === VerdictStatus::RequiresReview ? '. Primero tiene que pasar por Revision.' : '.');
        }

        $ultimo = $asset->directorReviews()->first();

        if ($ultimo?->status === DirectorReviewStatus::Pending) {
            return 'Ya esta enviada y pendiente del director.';
        }

        if ($ultimo?->status === DirectorReviewStatus::Approved) {
            return 'El director ya aprobo esta pieza.';
        }

        if ($ultimo?->status === DirectorReviewStatus::Returned && (int) $ultimo->validation_run_id === (int) $run->id) {
            return 'El director la devolvio. Sube una version corregida o revalidala antes de volver a enviarla.';
        }

        if ($this->directoresDe((int) $asset->brand_id)->isEmpty()) {
            return 'Esta marca no tiene ningun director asignado. Un administrador debe dar el rol director a alguien de su equipo.';
        }

        return null;
    }

    public function enviar(Asset $asset, User $user, ?string $nota = null): DirectorReview
    {
        $envio = DB::transaction(function () use ($asset, $user, $nota): DirectorReview {
            Asset::withoutGlobalScopes()->whereKey($asset->id)->lockForUpdate()->first();

            if (($motivo = $this->motivoParaNoEnviar($asset, $user)) !== null) {
                throw new RuntimeException($motivo);
            }

            $run = $asset->latestRun()->with(['verdict', 'humanReviews'])->firstOrFail();
            [$veredicto, $deHumano] = self::veredictoEfectivo($run);

            $envio = DirectorReview::create([
                'asset_id' => $asset->id,
                'validation_run_id' => $run->id,
                'brand_id' => $asset->brand_id,
                'sent_by' => $user->id,
                'sender_note' => filled($nota) ? trim((string) $nota) : null,
                'verdict_at_send' => $veredicto->value,
                'verdict_from_human' => $deHumano,
                'score_at_send' => $run->verdict?->score,
                'status' => DirectorReviewStatus::Pending->value,
            ]);

            $this->auditoria->log('director.sent', $envio, newValues: [
                'asset_id' => $asset->id,
                'validation_run_id' => $run->id,
                'verdict' => $veredicto->value,
                'verdict_from_human' => $deHumano,
                'note' => $envio->sender_note,
            ], brandId: (int) $asset->brand_id);

            return $envio;
        });

        // Fuera de la transaccion: si el correo falla, el envio ya quedo.
        Notification::send($this->directoresDe((int) $asset->brand_id)->reject(
            fn (User $d): bool => $d->id === $user->id
        ), new PiezaEnviadaAlDirector($envio));

        return $envio;
    }

    /**
     * Motivo por el que esta persona no puede decidir, o null si puede.
     */
    public function motivoParaNoDecidir(DirectorReview $envio, User $user, bool $aprobar): ?string
    {
        if (! $user->hasPermissionTo('director.decide')) {
            return 'Tu rol no puede aprobar ni devolver piezas.';
        }

        if (! $user->hasGlobalAccess() && ! $user->accessibleBrandIds()->contains((int) $envio->brand_id)) {
            return 'No tienes acceso a esta marca.';
        }

        if (! $envio->estaPendiente()) {
            return 'Este envio ya fue resuelto: '.$envio->status->label().'.';
        }

        // Separacion de funciones, igual que en la revision humana.
        $autor = $envio->asset?->submission?->user_id;

        if ((int) $envio->sent_by === (int) $user->id || ($autor !== null && (int) $autor === (int) $user->id)) {
            return 'No puedes decidir sobre una pieza que subiste o enviaste tu.';
        }

        // Devolver siempre se puede. Aprobar no, si una validacion posterior
        // de la misma pieza ya no la aprueba: seria aprobar contra la
        // evidencia mas reciente.
        if ($aprobar && ($posterior = $this->validacionPosterior($envio)) !== null) {
            [$v] = self::veredictoEfectivo($posterior);

            if ($v === null || ! $v->habilitaEnvio()) {
                return 'La pieza se revalido despues del envio y el veredicto ahora es '
                    .($v?->label() ?? 'sin veredicto').'. Devuelvela o pide que se revise.';
            }
        }

        return null;
    }

    /**
     * Ejecucion completada de la misma pieza posterior a la que se envio.
     */
    public function validacionPosterior(DirectorReview $envio): ?ValidationRun
    {
        return ValidationRun::withoutGlobalScopes()
            ->where('asset_id', $envio->asset_id)
            ->where('id', '>', $envio->validation_run_id)
            ->where('status', ValidationStatus::Completed->value)
            ->with(['verdict', 'humanReviews'])
            ->latest('id')
            ->first();
    }

    public function aprobar(DirectorReview $envio, User $user, ?string $comentario = null): DirectorReview
    {
        return $this->decidir($envio, $user, DirectorReviewStatus::Approved, $comentario);
    }

    public function devolver(DirectorReview $envio, User $user, string $comentario): DirectorReview
    {
        // Sin motivo, el disenador no sabe que corregir y vuelve a enviar lo mismo.
        if (mb_strlen(trim($comentario)) < self::COMENTARIO_MINIMO) {
            throw new RuntimeException('Para devolver una pieza escribe que hay que corregir (minimo '.self::COMENTARIO_MINIMO.' caracteres).');
        }

        return $this->decidir($envio, $user, DirectorReviewStatus::Returned, $comentario);
    }

    public function retirar(DirectorReview $envio, User $user): DirectorReview
    {
        return DB::transaction(function () use ($envio, $user): DirectorReview {
            $fila = $this->bloquear($envio);

            if (! $fila->estaPendiente()) {
                throw new RuntimeException('Este envio ya fue resuelto: '.$fila->status->label().'.');
            }

            if ((int) $fila->sent_by !== (int) $user->id && ! $user->hasRole('super_admin')) {
                throw new RuntimeException('Solo quien envio la pieza puede retirarla.');
            }

            $fila->update([
                'status' => DirectorReviewStatus::Withdrawn->value,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ]);

            $this->auditoria->log('director.withdrawn', $fila, brandId: (int) $fila->brand_id);

            return $fila;
        });
    }

    private function decidir(DirectorReview $envio, User $user, DirectorReviewStatus $estado, ?string $comentario): DirectorReview
    {
        $fila = DB::transaction(function () use ($envio, $user, $estado, $comentario): DirectorReview {
            $fila = $this->bloquear($envio);

            if (($motivo = $this->motivoParaNoDecidir($fila, $user, $estado === DirectorReviewStatus::Approved)) !== null) {
                throw new RuntimeException($motivo);
            }

            $fila->update([
                'status' => $estado->value,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_comment' => filled($comentario) ? trim((string) $comentario) : null,
            ]);

            $this->auditoria->log(
                $estado === DirectorReviewStatus::Approved ? 'director.approved' : 'director.returned',
                $fila,
                newValues: ['comment' => $fila->decision_comment, 'validation_run_id' => $fila->validation_run_id],
                brandId: (int) $fila->brand_id,
            );

            return $fila;
        });

        if (($remitente = User::query()->find($fila->sent_by)) !== null && $remitente->is_active) {
            $remitente->notify(new DecisionDelDirector($fila));
        }

        return $fila;
    }

    private function bloquear(DirectorReview $envio): DirectorReview
    {
        return DirectorReview::withoutGlobalScopes()
            ->whereKey($envio->id)
            ->lockForUpdate()
            ->with('asset.submission')
            ->firstOrFail();
    }
}
