<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BrandAsset;
use Illuminate\Support\Facades\Storage;

/**
 * Completa los metadatos del archivo al guardar.
 *
 * El formulario solo captura la ruta; dimensiones, peso y hash se derivan del
 * archivo. Hacerlo aqui evita duplicar esa logica en la creacion y la edicion.
 */
class BrandAssetObserver
{
    public function saving(BrandAsset $asset): void
    {
        if (! $asset->isDirty('storage_path') || blank($asset->storage_path)) {
            return;
        }

        $disk = $asset->storage_disk ?: 'public';
        $storage = Storage::disk($disk);

        if (! $storage->exists($asset->storage_path)) {
            return;
        }

        $absoluto = $storage->path($asset->storage_path);
        $dimensiones = @getimagesize($absoluto);

        $asset->storage_disk = $disk;
        $asset->file_hash = hash_file('sha256', $absoluto) ?: str_repeat('0', 64);
        $asset->mime_type = $storage->mimeType($asset->storage_path) ?: 'application/octet-stream';
        $asset->file_size = $storage->size($asset->storage_path);
        $asset->width = $dimensiones[0] ?? null;
        $asset->height = $dimensiones[1] ?? null;
    }
}
