<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DirectorReviewStatus;
use App\Models\DirectorReview;
use App\Services\Director\EnvioAlDirector;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

/**
 * Bandeja del director: piezas enviadas que esperan su decision, y el
 * historial de lo que ya decidio.
 *
 * Livewire plano, como Revision humana. Las reglas no viven aqui sino en
 * EnvioAlDirector; la pantalla solo pregunta y muestra el motivo cuando algo
 * no se puede.
 */
class BandejaDirector extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Operación';

    protected static ?string $navigationLabel = 'Director';

    protected static ?string $title = 'Bandeja del director';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'director';

    protected string $view = 'filament.pages.bandeja-director';

    public string $vista = 'pendientes';

    /** Envio con los hallazgos desplegados. */
    public ?string $abierto = null;

    /** @var array<string, string> public_id => comentario */
    public array $comentarios = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('director.decide') === true;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $n = DirectorReview::query()->where('status', DirectorReviewStatus::Pending->value)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    /**
     * El scope de marca de DirectorReview ya limita a las marcas del usuario.
     *
     * @return Collection<int, DirectorReview>
     */
    public function getEnviosProperty(): Collection
    {
        return DirectorReview::query()
            ->when(
                $this->vista === 'pendientes',
                fn (Builder $q) => $q->where('status', DirectorReviewStatus::Pending->value)->oldest('id'),
                fn (Builder $q) => $q->where('status', '!=', DirectorReviewStatus::Pending->value)->latest('decided_at')->limit(100),
            )
            ->with(['asset.submission', 'brand.client', 'sender', 'decider', 'validationRun.verdict'])
            ->get();
    }

    public function getPendientesProperty(): int
    {
        return DirectorReview::query()->where('status', DirectorReviewStatus::Pending->value)->count();
    }

    /**
     * Devoluciones anteriores de la misma pieza, para que el director vea si
     * ya pidio el mismo cambio antes.
     *
     * @return Collection<int, DirectorReview>
     */
    public function devolucionesPrevias(DirectorReview $envio): Collection
    {
        return DirectorReview::query()
            ->where('asset_id', $envio->asset_id)
            ->where('id', '<', $envio->id)
            ->where('status', DirectorReviewStatus::Returned->value)
            ->with('decider')
            ->latest('id')
            ->get();
    }

    public function ver(string $vista): void
    {
        $this->vista = $vista === 'historial' ? 'historial' : 'pendientes';
        $this->abierto = null;
    }

    public function alternar(string $publicId): void
    {
        $this->abierto = $this->abierto === $publicId ? null : $publicId;
    }

    public function aprobar(string $publicId): void
    {
        $this->resolver($publicId, fn (EnvioAlDirector $s, DirectorReview $e) => $s->aprobar($e, auth()->user(), $this->comentarios[$publicId] ?? null), 'Pieza aprobada');
    }

    public function devolver(string $publicId): void
    {
        $this->resolver($publicId, fn (EnvioAlDirector $s, DirectorReview $e) => $s->devolver($e, auth()->user(), (string) ($this->comentarios[$publicId] ?? '')), 'Pieza devuelta');
    }

    private function resolver(string $publicId, callable $accion, string $titulo): void
    {
        // Se busca con el scope de marca: un id ajeno no se encuentra.
        $envio = DirectorReview::query()->where('public_id', $publicId)->first();

        if ($envio === null) {
            Notification::make()->title('Envio no encontrado')->danger()->send();

            return;
        }

        try {
            $accion(app(EnvioAlDirector::class), $envio);
        } catch (RuntimeException $e) {
            Notification::make()->title('No se pudo registrar')->body($e->getMessage())->danger()->send();

            return;
        }

        unset($this->comentarios[$publicId]);
        $this->abierto = null;

        Notification::make()->title($titulo)->body('Se aviso a quien la envio.')->success()->send();
    }
}
