<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Pages;

use App\Filament\Resources\Submissions\SubmissionResource;
use App\Jobs\RunValidation;
use App\Services\AssetIngestor;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditSubmission extends EditRecord
{
    protected static string $resource = SubmissionResource::class;

    /** @var array<int, string> */
    protected array $archivos = [];

    /** @var array<string, string> */
    protected array $nombres = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * El campo de archivos arranca vacio siempre.
     *
     * Lo que ya se cargo no vive en una columna de submissions sino en assets,
     * y prellenarlo invitaria a "editar" una pieza que por diseno es inmutable:
     * quitar un archivo del panel no puede borrar una pieza que ya tiene
     * veredictos apuntandola.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['archivos'] = [];
        $data['archivos_nombres'] = [];

        return $data;
    }

    /**
     * 'archivos' no es una columna de submissions.
     *
     * Sin este paso, guardar la edicion intentaba un UPDATE sobre una columna
     * inexistente y reventaba con SQLSTATE[42S22]. La pagina de creacion ya
     * hacia esto mismo; a la de edicion le faltaba.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->archivos = array_values(array_filter((array) ($data['archivos'] ?? [])));

        unset($data['archivos']);

        // ruta guardada => nombre original del archivo (ver SubmissionForm).
        $this->nombres = array_filter((array) ($data['archivos_nombres'] ?? []), 'is_string');
        unset($data['archivos_nombres']);

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->archivos === []) {
            return;
        }

        $ingestor = app(AssetIngestor::class);
        $disco = config('filesystems.piezas_disk', 'local');

        $creados = 0;
        $repetidos = 0;
        $fallidos = [];

        foreach ($this->archivos as $path) {
            try {
                // Si ya existe una pieza en esta misma carga apuntando a esta
                // ruta, es la misma imagen byte por byte: la ruta lleva la
                // huella del contenido. Crear un asset nuevo solo agregaria
                // ruido al historial.
                $yaExiste = $this->record->assets()
                    ->where('storage_path', $path)
                    ->exists();

                if ($yaExiste) {
                    $repetidos++;

                    continue;
                }

                $asset = $ingestor->ingestStored($this->record, $path, $disco, $this->nombres[$path] ?? null);
                RunValidation::dispatch($asset, auth()->id());
                $creados++;
            } catch (Throwable $e) {
                $fallidos[] = basename($path).': '.$e->getMessage();
            }
        }

        if ($creados > 0) {
            Notification::make()
                ->title("{$creados} pieza(s) agregada(s)".(config('queue.default') === 'sync' ? ' y validada(s)' : ', validandose en segundo plano: te avisaremos al terminar'))
                ->body('Las piezas anteriores de esta carga no se modificaron.')
                ->success()
                ->send();
        }

        if ($repetidos > 0) {
            Notification::make()
                ->title("{$repetidos} archivo(s) ya estaban en esta carga")
                ->body('El contenido es identico al de una pieza existente. Si querias reevaluarla, usa Revalidar.')
                ->warning()
                ->send();
        }

        if ($fallidos !== []) {
            Notification::make()
                ->title('Algunas piezas no se pudieron procesar')
                ->body(implode(' | ', array_slice($fallidos, 0, 3)))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * Solo se redirige cuando hubo archivos nuevos, para que la tabla de piezas
     * se vuelva a consultar y aparezcan. Sin archivos, quedarse en la pagina es
     * el comportamiento esperado al guardar una edicion.
     */
    protected function getRedirectUrl(): ?string
    {
        return $this->archivos !== []
            ? static::getResource()::getUrl('edit', ['record' => $this->record])
            : null;
    }
}
