<?php

namespace App\Filament\Resources\BrandAssets\Pages;

use App\Filament\Resources\BrandAssets\BrandAssetResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBrandAsset extends EditRecord
{
    protected static string $resource = BrandAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
