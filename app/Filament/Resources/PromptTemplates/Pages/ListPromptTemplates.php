<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptTemplates\Pages;

use App\Filament\Resources\PromptTemplates\PromptTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromptTemplates extends ListRecords
{
    protected static string $resource = PromptTemplateResource::class;

    public function getSubheading(): ?string
    {
        return 'Lo que el modelo lee antes de juzgar una pieza. Publicado es inmutable, igual que las reglas.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva instruccion'),
        ];
    }
}
