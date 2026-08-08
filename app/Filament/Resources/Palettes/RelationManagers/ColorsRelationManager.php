<?php

declare(strict_types=1);

namespace App\Filament\Resources\Palettes\RelationManagers;

use App\Enums\ColorRole;
use App\Models\PaletteColor;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ColorsRelationManager extends RelationManager
{
    protected static string $relationship = 'colors';

    protected static ?string $title = 'Colores';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),

            ColorPicker::make('hex')
                ->label('Color')
                ->required()
                ->rgb(false)
                ->helperText('Se guarda en hexadecimal. La comparacion se hace en CIELAB, no sobre este valor.'),

            Select::make('role')
                ->label('Rol')
                ->options(fn (): array => collect(ColorRole::cases())
                    ->mapWithKeys(fn (ColorRole $r): array => [$r->value => $r->label()])
                    ->all())
                ->default(ColorRole::Secondary->value)
                ->required(),

            TextInput::make('delta_e_tolerance')
                ->label('Tolerancia propia')
                ->numeric()
                ->step(0.1)
                ->placeholder('Hereda la de la paleta')
                ->helperText('Un primario de marca suele exigir tolerancia mas estricta que un fondo.'),

            Toggle::make('is_forbidden')
                ->label('Color prohibido')
                ->helperText('Marcalo si su presencia en la pieza es un hallazgo, no un acierto. Ej. el color de un competidor.'),

            TextInput::make('sort_order')
                ->label('Orden')
                ->numeric()
                ->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                ColorColumn::make('hex')
                    ->label('')
                    ->alignCenter(),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('hex')
                    ->label('Hex')
                    ->copyable()
                    ->fontFamily('mono'),

                TextColumn::make('role')
                    ->label('Rol')
                    ->badge()
                    ->formatStateUsing(fn (ColorRole $state): string => $state->label()),

                TextColumn::make('delta_e_tolerance')
                    ->label('Delta E')
                    ->placeholder('Hereda')
                    ->alignCenter(),

                IconColumn::make('is_forbidden')
                    ->label('Prohibido')
                    ->boolean()
                    ->trueIcon('heroicon-o-no-symbol')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-o-check')
                    ->falseColor('gray'),
            ])
            ->headerActions([
                CreateAction::make()->label('Agregar color'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('sort_order')
            ->emptyStateHeading('Sin colores')
            ->emptyStateDescription('Una paleta vacia no permite validar nada.');
    }
}
