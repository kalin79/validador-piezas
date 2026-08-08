<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\FindingReviewState;
use App\Enums\RuleCategory;
use App\Enums\Severity;
use App\Enums\ValidationStatus;
use App\Enums\VerdictStatus;
use App\Models\ValidationRun;
use App\Services\Review\ReviewRecorder;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

/**
 * Cola de revision humana.
 *
 * Es el paso que faltaba para que el sistema pueda medirse. Mientras nadie
 * diga si un hallazgo era correcto, no hay forma de saber si el motor mejora
 * al cambiar una regla o un modelo: solo queda la impresion.
 *
 * Livewire plano, sin schemas de Filament, por la misma razon que las otras
 * pantallas criticas: la API de formularios es la que mas cambia entre
 * versiones y esta pantalla tiene que abrir siempre.
 */
class RevisionHumana extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Operacion';

    protected static ?string $navigationLabel = 'Revision';

    protected static ?string $title = 'Revision humana';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.revision-humana';

    public ?string $runId = null;

    /** @var array<int, string> */
    public array $decisiones = [];

    public ?string $veredictoFinal = null;

    public string $justificacion = '';

    /** @var array<int, array<string, string>> */
    public array $agregados = [];

    public string $nuevoCodigo = '';

    public string $nuevaCategoria = 'compliance';

    public string $nuevaSeveridad = 'major';

    public string $nuevaDescripcion = '';

    public static function getNavigationBadge(): ?string
    {
        $n = self::colaQuery()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Ejecuciones completadas, con veredicto y sin revisar, de las marcas que
     * el usuario puede ver.
     */
    protected static function colaQuery()
    {
        return ValidationRun::query()
            ->whereIn('brand_id', auth()->user()->accessibleBrandIds())
            ->where('status', ValidationStatus::Completed->value)
            ->whereHas('verdict')
            ->whereDoesntHave('humanReviews');
    }

    /** @return Collection<int, ValidationRun> */
    public function getColaProperty(): Collection
    {
        return self::colaQuery()
            ->with(['asset', 'brand', 'verdict'])
            ->orderBy('created_at')
            ->limit(40)
            ->get();
    }

    public function getRunProperty(): ?ValidationRun
    {
        if ($this->runId === null) {
            return null;
        }

        return ValidationRun::query()
            ->where('public_id', $this->runId)
            ->whereIn('brand_id', auth()->user()->accessibleBrandIds())
            ->with(['asset', 'brand.client', 'verdict', 'findings'])
            ->first();
    }

    /** @return array<string, string> */
    public function getCategoriasProperty(): array
    {
        return collect(RuleCategory::cases())
            ->mapWithKeys(fn (RuleCategory $c): array => [$c->value => $c->label()])
            ->all();
    }

    /** @return array<string, string> */
    public function getSeveridadesProperty(): array
    {
        return collect(Severity::cases())
            ->mapWithKeys(fn (Severity $s): array => [$s->value => $s->label()])
            ->all();
    }

    public function abrir(string $publicId): void
    {
        $this->reiniciar();
        $this->runId = $publicId;

        $run = $this->run;

        if ($run === null) {
            return;
        }

        // Por defecto todo se da por correcto: el revisor solo marca las
        // excepciones. Si el valor por defecto fuera "sin decidir", una
        // revision apurada dejaria la mayoria sin dato y las metricas
        // quedarian con huecos.
        foreach ($run->findings as $f) {
            $this->decisiones[$f->id] = FindingReviewState::Confirmed->value;
        }

        $this->veredictoFinal = $run->verdict?->status->value;
    }

    public function alternar(int $findingId): void
    {
        $actual = $this->decisiones[$findingId] ?? FindingReviewState::Confirmed->value;

        $this->decisiones[$findingId] = $actual === FindingReviewState::Confirmed->value
            ? FindingReviewState::FalsePositive->value
            : FindingReviewState::Confirmed->value;
    }

    public function agregarHallazgo(): void
    {
        if (blank($this->nuevaDescripcion)) {
            return;
        }

        $this->agregados[] = [
            'rule_code' => trim($this->nuevoCodigo),
            'category' => $this->nuevaCategoria,
            'severity' => $this->nuevaSeveridad,
            'description' => trim($this->nuevaDescripcion),
        ];

        $this->nuevoCodigo = '';
        $this->nuevaDescripcion = '';
    }

    public function quitarHallazgo(int $indice): void
    {
        unset($this->agregados[$indice]);
        $this->agregados = array_values($this->agregados);
    }

    public function guardar(): void
    {
        $run = $this->run;

        if ($run === null) {
            return;
        }

        try {
            app(ReviewRecorder::class)->record(
                run: $run,
                reviewerId: auth()->id(),
                veredictoFinal: VerdictStatus::from($this->veredictoFinal ?? ''),
                decisiones: $this->decisiones,
                agregados: $this->agregados,
                justificacion: filled($this->justificacion) ? $this->justificacion : null,
            );

            Notification::make()
                ->title('Revision registrada')
                ->body('Ya cuenta para las metricas de calidad del motor.')
                ->success()
                ->send();

            $this->reiniciar();
        } catch (Throwable $e) {
            Notification::make()
                ->title('No se pudo guardar')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function reiniciar(): void
    {
        $this->runId = null;
        $this->decisiones = [];
        $this->agregados = [];
        $this->veredictoFinal = null;
        $this->justificacion = '';
        $this->nuevoCodigo = '';
        $this->nuevaDescripcion = '';
    }

    /**
     * Confirmar o descartar hallazgos alimenta las metricas de calibracion:
     * quien revisa mal, calibra mal.
     *
     * Las Pages de Filament no pasan por Policy: sin canAccess() quedan
     * abiertas a cualquiera que sepa la URL, aunque el enlace no se vea en el
     * menu.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('review.perform') === true;
    }
}
