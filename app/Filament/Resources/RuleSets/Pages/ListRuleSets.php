<?php

namespace App\Filament\Resources\RuleSets\Pages;

use App\Filament\Resources\RuleSets\RuleSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRuleSets extends ListRecords
{
    protected static string $resource = RuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
