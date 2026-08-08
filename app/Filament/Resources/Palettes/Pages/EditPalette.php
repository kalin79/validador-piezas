<?php

namespace App\Filament\Resources\Palettes\Pages;

use App\Filament\Resources\Palettes\PaletteResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPalette extends EditRecord
{
    protected static string $resource = PaletteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
