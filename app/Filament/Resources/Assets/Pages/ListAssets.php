<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use Filament\Resources\Pages\ListRecords;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    public function getTitle(): string
    {
        return 'Piezas validadas';
    }

    public function getSubheading(): ?string
    {
        return 'Todas las piezas evaluadas, sin importar por donde entraron.';
    }

    /**
     * Sin accion de crear: las piezas nacen de una carga o de la API.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
