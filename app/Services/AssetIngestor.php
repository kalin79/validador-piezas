<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\Submission;
use App\Support\Image\LimiteDePixeles;
use App\Support\Image\PaletteExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Convierte un archivo subido en un Asset con sus metadatos extraidos.
 *
 * Todo lo que se calcula aqui es determinista y barato: hash, dimensiones,
 * paleta. Se hace una sola vez al ingresar la pieza, no en cada validacion.
 */
final class AssetIngestor
{
    /** @var array<int, string> */
    private const MIMES_PERMITIDOS = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        private PaletteExtractor $extractor = new PaletteExtractor(),
    ) {}

    public function ingest(Submission $submission, UploadedFile $file, ?string $disk = null): Asset
    {
        $mime = $file->getMimeType();

        if (! in_array($mime, self::MIMES_PERMITIDOS, true)) {
            throw new RuntimeException(
                "Formato no admitido: {$mime}. Se aceptan JPG, PNG y WEBP."
            );
        }

        $disk ??= config('filesystems.piezas_disk', 'local');

        LimiteDePixeles::verificar($file->getRealPath());

        // El hash se calcula sobre el archivo temporal, antes de moverlo:
        // es la huella del binario que el usuario subio, no del que quedo guardado.
        $hash = hash_file('sha256', $file->getRealPath());

        if ($hash === false) {
            throw new RuntimeException('No se pudo calcular el hash del archivo.');
        }

        $dimensiones = @getimagesize($file->getRealPath());

        $paleta = [];

        try {
            $paleta = array_map(
                static fn ($c): array => $c->toArray(),
                $this->extractor->extract($file->getRealPath())
            );
        } catch (\Throwable) {
            // Una pieza sin paleta legible sigue siendo ingresable: el
            // evaluador de color simplemente no producira hallazgos.
            $paleta = [];
        }

        $path = $file->store(
            sprintf('piezas/%d/%s', $submission->brand_id, now()->format('Y/m')),
            $disk
        );

        if ($path === false) {
            throw new RuntimeException('No se pudo almacenar el archivo.');
        }

        return Asset::create([
            'submission_id' => $submission->id,
            'brand_id' => $submission->brand_id,
            'original_filename' => $file->getClientOriginalName(),
            'storage_disk' => $disk,
            'storage_path' => $path,
            'file_hash' => $hash,
            'mime_type' => $mime,
            'file_size' => $file->getSize(),
            'width' => $dimensiones[0] ?? null,
            'height' => $dimensiones[1] ?? null,
            'extracted_palette' => $paleta,
            'extracted_metadata' => [
                'ingested_at' => now()->toIso8601String(),
                'palette_colors' => count($paleta),
            ],
        ]);
    }

    /**
     * Crea el Asset a partir de un archivo que ya esta en el disco.
     *
     * Es la ruta que usa el panel: Filament guarda el archivo al enviar el
     * formulario y aqui solo se extraen los metadatos.
     */
    public function ingestStored(Submission $submission, string $path, ?string $disk = null): Asset
    {
        $disk ??= config('filesystems.piezas_disk', 'local');
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            throw new RuntimeException("El archivo no existe en el disco {$disk}: {$path}");
        }

        $absoluto = $storage->path($path);
        $mime = $storage->mimeType($path) ?: 'application/octet-stream';

        if (! in_array($mime, self::MIMES_PERMITIDOS, true)) {
            throw new RuntimeException(
                "Formato no admitido: {$mime}. Se aceptan JPG, PNG y WEBP."
            );
        }

        LimiteDePixeles::verificar($absoluto);

        $hash = hash_file('sha256', $absoluto);

        if ($hash === false) {
            throw new RuntimeException('No se pudo calcular el hash del archivo.');
        }

        $dimensiones = @getimagesize($absoluto);
        $paleta = [];

        try {
            $paleta = array_map(
                static fn ($c): array => $c->toArray(),
                $this->extractor->extract($absoluto)
            );
        } catch (\Throwable) {
            $paleta = [];
        }

        return Asset::create([
            'submission_id' => $submission->id,
            'brand_id' => $submission->brand_id,
            'original_filename' => basename($path),
            'storage_disk' => $disk,
            'storage_path' => $path,
            'file_hash' => $hash,
            'mime_type' => $mime,
            'file_size' => $storage->size($path),
            'width' => $dimensiones[0] ?? null,
            'height' => $dimensiones[1] ?? null,
            'extracted_palette' => $paleta,
            'extracted_metadata' => [
                'ingested_at' => now()->toIso8601String(),
                'palette_colors' => count($paleta),
            ],
        ]);
    }

    /**
     * Reprocesa la extraccion sobre una pieza ya almacenada.
     */
    public function reextract(Asset $asset): Asset
    {
        $ruta = Storage::disk($asset->storage_disk)->path($asset->storage_path);

        $paleta = array_map(
            static fn ($c): array => $c->toArray(),
            $this->extractor->extract($ruta)
        );

        $asset->update(['extracted_palette' => $paleta]);

        return $asset->refresh();
    }
}
