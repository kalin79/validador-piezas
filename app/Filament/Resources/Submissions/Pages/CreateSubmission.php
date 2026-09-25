<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Pages;

use App\Filament\Resources\Submissions\SubmissionResource;
use App\Jobs\RunValidation;
use App\Services\AssetIngestor;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Throwable;

class CreateSubmission extends CreateRecord
{
    protected static string $resource = SubmissionResource::class;

    /** @var array<int, string> */
    protected array $archivos = [];

    /** @var array<string, string> */
    protected array $nombres = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 'archivos' no es una columna de submissions: se guarda aparte para
        // convertirlo en Assets una vez que la carga ya tiene id.
        $this->archivos = (array) ($data['archivos'] ?? []);
        unset($data['archivos']);

        // ruta guardada => nombre original del archivo (ver SubmissionForm).
        $this->nombres = array_filter((array) ($data['archivos_nombres'] ?? []), 'is_string');
        unset($data['archivos_nombres']);

        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $ingestor = app(AssetIngestor::class);
        $disco = config('filesystems.piezas_disk', 'local');

        $creados = 0;
        $fallidos = [];

        foreach ($this->archivos as $path) {
            try {
                $asset = $ingestor->ingestStored($this->record, $path, $disco, $this->nombres[$path] ?? null);
                RunValidation::dispatch($asset, auth()->id());
                $creados++;
            } catch (Throwable $e) {
                $fallidos[] = basename($path).': '.$e->getMessage();
            }
        }

        if ($creados > 0) {
            Notification::make()
                ->title("{$creados} pieza(s) ingresada(s)".(config('queue.default') === 'sync' ? ' y validada(s)' : ', validandose en segundo plano: te avisaremos al terminar'))
                ->success()
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

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
