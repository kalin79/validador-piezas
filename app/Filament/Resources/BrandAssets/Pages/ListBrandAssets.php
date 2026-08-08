<?php

namespace App\Filament\Resources\BrandAssets\Pages;

use App\Filament\Resources\BrandAssets\BrandAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBrandAssets extends ListRecords
{
    protected static string $resource = BrandAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
