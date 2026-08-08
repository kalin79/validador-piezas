<?php

namespace App\Filament\Resources\Palettes\Pages;

use App\Filament\Resources\Palettes\PaletteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPalettes extends ListRecords
{
    protected static string $resource = PaletteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
