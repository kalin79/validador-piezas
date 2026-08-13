<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Brand;
use App\Models\Client;
use App\Services\RuleResolver;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Selector de contexto de trabajo.
 *
 * El contexto no otorga permisos: define en que marca caen las piezas que se
 * cargan. Cada cambio se revalida contra los accesos reales del usuario.
 */
class SeleccionarContexto extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Operacion';

    protected static ?string $navigationLabel = 'Cambiar contexto';

    protected static ?string $title = 'Seleccionar cliente y marca';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.seleccionar-contexto';

    public ?int $clientId = null;

    public ?int $brandId = null;

    public function mount(): void
    {
        $current = auth()->user()->activeBrand;

        $this->brandId = $current?->id;
        $this->clientId = $current?->client_id;

        // Si solo hay un cliente accesible, no tiene sentido hacerlo elegir.
        if ($this->clientId === null && $this->clients->count() === 1) {
            $this->clientId = $this->clients->first()->id;
        }
    }

    /** @return Collection<int, Client> */
    public function getClientsProperty(): Collection
    {
        return Client::query()
            ->whereIn('id', auth()->user()->accessibleClientIds())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Brand> */
    public function getBrandsProperty(): Collection
    {
        if ($this->clientId === null) {
            return collect();
        }

        return Brand::query()
            ->whereIn('id', auth()->user()->accessibleBrandIds())
            ->where('client_id', $this->clientId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Vista previa de las reglas que aplicarian en la marca seleccionada.
     *
     * Sirve para confirmar que el contexto es el correcto antes de cargar nada:
     * si una marca muestra cero reglas, validar contra ella no serviria de nada.
     *
     * @return array{total: int, heredadas: int, propias: int, sobrescritas: int}|null
     */
    public function getPreviewProperty(): ?array
    {
        if ($this->brandId === null) {
            return null;
        }

        $brand = Brand::find($this->brandId);

        if ($brand === null || !auth()->user()->canAccessBrand($brand->id)) {
            return null;
        }

        $snapshot = collect(app(RuleResolver::class)->resolve($brand)->snapshot);

        return [
            'total' => $snapshot->count(),
            'heredadas' => $snapshot->where('origin', 'client')->count(),
            'propias' => $snapshot->where('origin', 'brand')->count(),
            'sobrescritas' => $snapshot->where('origin', 'brand_override')->count(),
        ];
    }

    /**
     * Hay una seleccion distinta del contexto guardado que aun no se confirma.
     */
    public function getHayCambioPendienteProperty(): bool
    {
        return $this->brandId !== null
            && $this->brandId !== auth()->user()->active_brand_id;
    }

    public function updatedClientId(): void
    {
        $this->brandId = null;
    }

    public function guardar(): void
    {
        if ($this->brandId === null) {
            Notification::make()
                ->title('Selecciona una marca')
                ->warning()
                ->send();

            return;
        }

        // Revalida contra los accesos reales: no confia en el desplegable.
        if (!auth()->user()->switchToBrand($this->brandId)) {
            Notification::make()
                ->title('No tienes acceso a esa marca')
                ->body('Si crees que es un error, pide que te agreguen al equipo correspondiente.')
                ->danger()
                ->send();

            return;
        }

        $brand = Brand::find($this->brandId);

        Notification::make()
            ->title('Contexto actualizado')
            ->body("Trabajando en {$brand->fullName()}")
            ->success()
            ->send();

        $this->redirect(static::getUrl(), navigate: true);
    }
}
