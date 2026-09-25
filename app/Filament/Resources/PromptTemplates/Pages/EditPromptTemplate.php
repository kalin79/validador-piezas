<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptTemplates\Pages;

use App\Enums\RuleSetStatus;
use App\Filament\Resources\PromptTemplates\PromptTemplateResource;
use App\Models\PromptTemplate;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPromptTemplate extends EditRecord
{
    protected static string $resource = PromptTemplateResource::class;

    public function getSubheading(): ?string
    {
        return 'Alcance: '.$this->record->alcance();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publicar')
                ->label('Publicar')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->modalDescription(fn (PromptTemplate $record): string => sprintf(
                    'Una vez publicada no se puede editar, y las ejecuciones que la usen guardaran esta version para siempre. '
                    .'Se retira la version anterior del mismo alcance (%s); los otros niveles no se tocan.',
                    $record->alcance(),
                ))
                ->visible(fn (PromptTemplate $record): bool => $record->status === RuleSetStatus::Draft)
                ->authorize('publish')
                ->action(function (PromptTemplate $record): void {
                    // Bloqueo, verificacion y retiro en una sola transaccion
                    // (ver App\Services\Publicacion).
                    try {
                        app(\App\Services\Publicacion::class)->publicarPlantilla($record);
                    } catch (\RuntimeException $e) {
                        Notification::make()
                            ->title('No se publico')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Publicada la version {$record->version}")
                        ->body('Las validaciones nuevas de '.$record->alcance().' ya la usan.')
                        ->success()
                        ->send();
                }),

            Action::make('nueva_version')
                ->label('Nueva version')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Se crea un borrador con este mismo contenido y alcance. Esta version queda intacta y sigue vigente hasta que publiques la nueva.')
                ->visible(fn (PromptTemplate $record): bool => $record->status !== RuleSetStatus::Draft)
                ->authorize(fn (PromptTemplate $record): bool => (bool) auth()->user()?->can('create', PromptTemplate::class)
                    && (bool) auth()->user()?->can('view', $record))
                ->action(function (PromptTemplate $record) {
                    $siguiente = PromptTemplate::siguienteVersion(
                        $record->key,
                        $record->client_id,
                        $record->brand_id,
                    );

                    // Copia campo por campo y no replicate(): asi ningun
                    // atributo calculado se arrastra sin que sea explicito.
                    $nueva = PromptTemplate::create([
                        'client_id' => $record->client_id,
                        'brand_id' => $record->brand_id,
                        'key' => $record->key,
                        'version' => $siguiente,
                        'name' => preg_replace('/ v\d+$/', '', $record->name)." v{$siguiente}",
                        'system_prompt' => $record->system_prompt,
                        'user_prompt_template' => $record->user_prompt_template,
                        'output_schema' => $record->output_schema,
                        'status' => RuleSetStatus::Draft->value,
                    ]);

                    Notification::make()
                        ->title("Borrador v{$siguiente} creado")
                        ->success()
                        ->send();

                    return redirect(static::getResource()::getUrl('edit', ['record' => $nueva]));
                }),
        ];
    }
}
