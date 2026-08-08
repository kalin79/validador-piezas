<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Brand;
use App\Models\ValidationRun;
use App\Services\QuickValidation;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;
use UnitEnum;

/**
 * Validacion de una pieza suelta: marca e imagen, sin contexto de campana.
 *
 * Escrita con Livewire plano y no con un schema de Filament, por la misma
 * razon que la pagina de contexto: la API de formularios es la que mas cambio
 * entre versiones, y esta pantalla tiene que funcionar el dia de la demo.
 *
 * Usa el mismo servicio que la API del plugin. Si algo se comporta distinto
 * aqui que desde Figma, es un error, no una diferencia de diseno.
 */
class ValidacionRapida extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Operacion';

    protected static ?string $navigationLabel = 'Validacion rapida';

    protected static ?string $title = 'Validacion rapida';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.validacion-rapida';

    public ?string $marca = null;

    public $imagen = null;

    public ?string $modelo = null;

    public ?string $runId = null;

    public bool $procesando = false;

    public function mount(): void
    {
        $activa = auth()->user()?->activeBrand;

        if ($activa !== null) {
            $this->marca = $activa->client->slug.'/'.$activa->slug;
        }

        $this->modelo = (string) config('ai.model');
    }

    /**
     * @return array<string, string>
     */
    public function getMarcasProperty(): array
    {
        return Brand::query()
            ->whereIn('id', auth()->user()->accessibleBrandIds())
            ->where('is_active', true)
            ->with('client')
            ->get()
            ->sortBy(fn (Brand $b): string => $b->fullName())
            ->mapWithKeys(fn (Brand $b): array => [
                $b->client->slug.'/'.$b->slug => $b->fullName(),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function getModelosProperty(): array
    {
        return (array) config('ai.available_models', []);
    }

    public function getRunProperty(): ?ValidationRun
    {
        if ($this->runId === null) {
            return null;
        }

        return ValidationRun::query()
            ->where('public_id', $this->runId)
            ->with(['verdict', 'findings', 'asset'])
            ->first();
    }

    public function validar(): void
    {
        $this->validate([
            'marca' => ['required', 'string'],
            'imagen' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
        ], [
            'marca.required' => 'Elige una marca.',
            'imagen.required' => 'Sube una imagen.',
            'imagen.max' => 'La imagen no puede pasar de 20 MB.',
        ]);

        $this->procesando = true;
        $this->runId = null;

        try {
            // Se revalida el acceso aunque el desplegable solo ofrezca marcas
            // permitidas: filtrar la vista no es seguridad, alguien puede
            // enviar el formulario con otro valor.
            $brand = QuickValidation::resolveBrand(
                $this->marca,
                auth()->user()->accessibleBrandIds()->all(),
            );

            /** @var TemporaryUploadedFile $archivo */
            $archivo = $this->imagen;

            $run = app(QuickValidation::class)->validate(
                brand: $brand,
                file: $archivo,
                userId: auth()->id(),
                source: 'panel_rapido',
                channel: null,
                externalRef: null,
                model: $this->modelo ?: null,
            );

            $this->runId = $run->public_id;
            $this->imagen = null;

            Notification::make()
                ->title('Validacion completada')
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('No se pudo validar')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        } finally {
            $this->procesando = false;
        }
    }

    public function limpiar(): void
    {
        $this->runId = null;
        $this->imagen = null;
    }

    /**
     * Cada validacion cuesta tokens reales. El auditor ve todo y no gasta nada.
     *
     * Las Pages de Filament no pasan por Policy: sin canAccess() quedan
     * abiertas a cualquiera que sepa la URL, aunque el enlace no se vea en el
     * menu.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('validation.trigger') === true;
    }
}
