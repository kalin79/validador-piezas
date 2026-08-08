<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptTemplates\Schemas;

use App\Enums\RuleSetStatus;
use App\Models\Brand;
use App\Models\Client;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PromptTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Alcance')
                ->description('Sin cliente, la instruccion aplica a todos. Con cliente y sin marca, a todas las marcas de ese cliente. Con marca, solo a esa. Siempre gana la mas especifica.')
                ->columns(2)
                ->schema([
                    Select::make('client_id')
                        ->label('Cliente')
                        ->options(fn (): array => Client::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->placeholder('Todos los clientes')
                        ->live()
                        ->disabledOn('edit')
                        // Al cambiar de cliente, la marca elegida deja de tener
                        // sentido: puede pertenecer a otro.
                        ->afterStateUpdated(fn (callable $set) => $set('brand_id', null))
                        ->helperText('Dejalo vacio para una instruccion general.'),

                    Select::make('brand_id')
                        ->label('Marca')
                        ->options(function (callable $get): array {
                            if (blank($get('client_id'))) {
                                return [];
                            }

                            return Brand::query()
                                ->where('client_id', $get('client_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->placeholder('Todas las marcas del cliente')
                        ->disabled(fn (callable $get): bool => blank($get('client_id')))
                        ->disabledOn('edit')
                        ->helperText(fn (callable $get): string => blank($get('client_id'))
                            ? 'Elige un cliente para poder acotar a una marca.'
                            : 'Dejala vacia para que aplique a todas las marcas del cliente.'),
                ]),

            Section::make('Identificacion')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    TextInput::make('key')
                        ->label('Clave')
                        ->default('piece_validation')
                        ->required()
                        ->maxLength(60)
                        ->disabledOn('edit')
                        ->helperText('Identifica para que sirve. El validador busca "piece_validation".'),

                    TextInput::make('version')
                        ->label('Version')
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('se calcula al guardar')
                        ->visibleOn('edit'),
                ]),

            Section::make('Instrucciones')
                ->description('Publicado es inmutable. Para cambiar algo se crea una version nueva, igual que con las reglas.')
                ->schema([
                    Textarea::make('system_prompt')
                        ->label('Instruccion de sistema')
                        ->rows(18)
                        ->required()
                        ->disabled(fn (?string $operation, $record): bool => $operation === 'edit'
                            && $record?->status !== RuleSetStatus::Draft)
                        ->helperText('Define como debe juzgar: que priorizar, cuando dudar, que no hacer. Es la parte que mas cambia el resultado.')
                        ->columnSpanFull(),

                    Textarea::make('user_prompt_template')
                        ->label('Plantilla del mensaje')
                        ->rows(14)
                        ->required()
                        ->disabled(fn (?string $operation, $record): bool => $operation === 'edit'
                            && $record?->status !== RuleSetStatus::Draft)
                        ->helperText('Aqui se insertan las reglas, la marca y los hallazgos ya medidos por codigo. Los marcadores entre llaves los reemplaza el sistema.')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
