<?php

namespace App\Filament\Resources\RuleSets\Pages;

use App\Filament\Resources\RuleSets\RuleSetResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditRuleSet extends EditRecord
{
    protected static string $resource = RuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
