<?php

declare(strict_types=1);

namespace App\Filament\Resources\RuleSets\RelationManagers;

use Closure;
use App\Enums\OverrideAction;
use App\Enums\RuleCategory;
use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Enums\RuleType;
use App\Enums\Severity;
use App\Models\Palette;
use App\Models\Rule;
use App\Models\RuleSet;
use App\Services\Validation\DeterministicEngine;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RulesRelationManager extends RelationManager
{
    protected static string $relationship = 'rules';

    protected static ?string $title = 'Reglas';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificación')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('Código')
                        ->required()
                        ->maxLength(50)
                        ->rule(fn (RelationManager $livewire, ?Rule $record): Closure => static function (
                            string $attribute,
                            mixed $value,
                            Closure $fail
                        ) use ($livewire, $record): void {
                            $existe = Rule::query()
                                ->where('rule_set_id', $livewire->getOwnerRecord()->getKey())
                                ->where('code', $value)
                                ->when($record !== null, fn ($q) => $q->whereKeyNot($record->getKey()))
                                ->exists();

                            if ($existe) {
                                $fail("Ya existe una regla con el código {$value} en este conjunto.");
                            }
                        })
                        ->helperText(fn (RelationManager $livewire): string => self::convencionDeCodigo($livewire->getOwnerRecord())),

                    TextInput::make('title')
                        ->label('Título')
                        ->required()
                        ->maxLength(255),

                    Select::make('category')
                        ->label('Categoría')
                        ->options(RuleCategory::options())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, callable $set): void {
                            if ($state === null) {
                                return;
                            }

                            $set('severity', RuleCategory::from($state)->defaultSeverity()->value);

                            // Si la categoria nueva no tiene evaluador, una regla
                            // determinista quedaria muda: el motor la filtra y no
                            // avisa. Se fuerza a juicio antes de que eso pase.
                            if (! in_array($state, self::categoriasDeterministas(), true)) {
                                $set('type', RuleType::Judgment->value);
                            }
                        }),

                    Select::make('type')
                        ->label('Tipo de evaluación')
                        ->options(function (callable $get): array {
                            $juicio = [RuleType::Judgment->value => RuleType::Judgment->label()];

                            if (! in_array($get('category'), self::categoriasDeterministas(), true)) {
                                return $juicio;
                            }

                            return [RuleType::Deterministic->value => RuleType::Deterministic->label()] + $juicio;
                        })
                        ->default(RuleType::Judgment->value)
                        ->required()
                        ->helperText(function (callable $get): string {
                            if (in_array($get('category'), self::categoriasDeterministas(), true)) {
                                return 'Determinista la evalúa el código; de juicio, el modelo.';
                            }

                            return 'Esta categoría no tiene evaluador determinista, así que solo admite juicio. '
                                .'Una regla determinista aquí no se evaluaría y el motor no avisaría.';
                        }),

                    Select::make('severity')
                        ->label('Severidad')
                        ->options(Severity::options())
                        ->required()
                        ->helperText('Bloqueante fuerza rechazo, sin importar el puntaje.'),

                    Select::make('palette_id')
                        ->label('Paleta asociada')
                        ->options(fn (RelationManager $livewire): array => self::palettesFor($livewire->getOwnerRecord()))
                        ->searchable()
                        ->visible(fn (callable $get): bool => $get('category') === RuleCategory::Palette->value),
                ]),

            Section::make('Enunciado')
                ->schema([
                    Textarea::make('statement')
                        ->label('Qué debe cumplir la pieza')
                        ->required()
                        ->rows(4)
                        ->helperText('Se envía textualmente al modelo, solo en reglas de juicio. Escríbelo como se lo explicarías a un revisor nuevo: concreto y verificable.'),

                    TagsInput::make('applies_to_channels')
                        ->label('Canales donde aplica')
                        ->placeholder('Dejar vacío para todos')
                        ->suggestions([
                            'instagram_post', 'instagram_story', 'instagram_reel',
                            'facebook_feed', 'facebook_story',
                            'tiktok_video', 'linkedin_post', 'youtube_thumbnail',
                            'display_banner', 'email',
                        ]),
                ]),

            Section::make('Ejemplos')
                ->description('Se envían al modelo junto al enunciado, bajo las etiquetas Cumple y No cumple. Son la forma más efectiva de afinar una regla: dos ejemplos concretos comunican más que tres párrafos de explicación.')
                ->columns(2)
                ->collapsed(fn (?Rule $record): bool => $record !== null
                    && blank($record->positive_examples)
                    && blank($record->negative_examples))
                ->schema([
                    Repeater::make('positive_examples')
                        ->label('Ejemplos que cumplen')
                        ->simple(
                            TextInput::make('texto')
                                ->placeholder('Ej. Empieza a invertir desde S/ 100')
                                ->maxLength(500)
                                ->required()
                        )
                        ->addActionLabel('Agregar ejemplo')
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->helperText('Copy real de piezas aprobadas.'),

                    Repeater::make('negative_examples')
                        ->label('Ejemplos que no cumplen')
                        ->simple(
                            TextInput::make('texto')
                                ->placeholder('Ej. Rentabilidad asegurada del 12% anual')
                                ->maxLength(500)
                                ->required()
                        )
                        ->addActionLabel('Agregar ejemplo')
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->helperText('Casos que el equipo rechazó. Son los más útiles: le muestran al modelo el límite exacto.'),
                ]),

            Section::make('Herencia')
                ->description('Solo aplica en conjuntos de marca: permite anular una regla heredada del cliente.')
                ->columns(2)
                ->visible(fn (RelationManager $livewire): bool => $livewire->getOwnerRecord()->owner_type === RuleSetOwnerType::Brand)
                ->schema([
                    Select::make('override_action')
                        ->label('Acción sobre la heredada')
                        ->options([
                            OverrideAction::Replace->value => OverrideAction::Replace->label(),
                            OverrideAction::Disable->value => OverrideAction::Disable->label(),
                        ])
                        ->placeholder('Ninguna: es una regla propia')
                        ->live(),

                    TextInput::make('overrides_code')
                        ->label('Código de la regla que anula')
                        ->maxLength(50)
                        ->visible(fn (callable $get): bool => filled($get('override_action')))
                        ->rule(fn (RelationManager $livewire, ?Rule $record): Closure => static function (
                            string $attribute,
                            mixed $value,
                            Closure $fail
                        ) use ($livewire, $record): void {
                            if (blank($value)) {
                                return;
                            }

                            // Dos overrides al mismo codigo compiten por la misma
                            // posicion en el conjunto efectivo y gana el ultimo
                            // procesado. Es determinista pero no evidente: uno de
                            // los dos quedaria muerto sin que nadie lo note.
                            $conflicto = Rule::query()
                                ->where('rule_set_id', $livewire->getOwnerRecord()->getKey())
                                ->where('overrides_code', $value)
                                ->when($record !== null, fn ($q) => $q->whereKeyNot($record->getKey()))
                                ->first();

                            if ($conflicto !== null) {
                                $fail("La regla {$conflicto->code} ya anula a {$value} en este conjunto. Edita ésa en vez de crear otra.");
                            }
                        })
                        ->helperText('Si lo dejas vacío se usa el código de esta regla. Solo puede haber una anulación por cada regla heredada.'),
                ]),

            Section::make('Control')
                ->columns(3)
                ->schema([
                    Toggle::make('is_locked')
                        ->label('No anulable por las marcas')
                        ->helperText('Solo en conjuntos de cliente. Úsalo en reglas normativas.')
                        ->visible(fn (RelationManager $livewire): bool => $livewire->getOwnerRecord()->owner_type === RuleSetOwnerType::Client),

                    Toggle::make('is_active')
                        ->label('Activa')
                        ->default(true),

                    TextInput::make('sort_order')
                        ->label('Orden')
                        ->numeric()
                        ->default(0),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->wrap()
                    ->limit(60),

                TextColumn::make('category')
                    ->label('Categoría')
                    ->badge()
                    ->formatStateUsing(fn (RuleCategory $state): string => $state->label()),

                TextColumn::make('severity')
                    ->label('Severidad')
                    ->badge()
                    ->formatStateUsing(fn (Severity $state): string => $state->label())
                    ->color(fn (Severity $state): string => $state->color()),

                TextColumn::make('type')
                    ->label('Evalúa')
                    ->formatStateUsing(fn (RuleType $state): string => $state === RuleType::Deterministic ? 'Código' : 'IA')
                    ->badge()
                    ->color('gray'),

                IconColumn::make('is_locked')
                    ->label('No anulable')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->falseColor('gray'),

                TextColumn::make('overrides_code')
                    ->label('Anula a')
                    ->placeholder('—')
                    ->badge()
                    ->color('warning'),

                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoría')
                    ->options(RuleCategory::options()),

                SelectFilter::make('severity')
                    ->label('Severidad')
                    ->options(Severity::options()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nueva regla')
                    ->visible(fn (RelationManager $livewire): bool => self::isEditable($livewire->getOwnerRecord())),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ver')
                    ->modalHeading(fn (Rule $record): string => "{$record->code} · {$record->title}"),

                EditAction::make()
                    ->visible(fn (RelationManager $livewire): bool => self::isEditable($livewire->getOwnerRecord())),

                DeleteAction::make()
                    ->visible(fn (RelationManager $livewire): bool => self::isEditable($livewire->getOwnerRecord())),
            ])
            ->emptyStateHeading('Sin reglas')
            ->emptyStateDescription('Un conjunto sin reglas no se puede publicar.')
            ->defaultSort('sort_order')
            ->paginated([25, 50, 100]);
    }

    /**
     * Un conjunto publicado es inmutable: sus reglas no se tocan.
     */
    private static function isEditable(RuleSet $ruleSet): bool
    {
        return $ruleSet->status === RuleSetStatus::Draft;
    }

    /**
     * Categorias con evaluador determinista, preguntadas al motor.
     *
     * Sin esto el formulario deja crear, por ejemplo, una regla de Redaccion
     * marcada como determinista: se guarda, se publica, y nunca produce un
     * hallazgo. El hueco existia antes de agregar Estrategia; agregarla solo
     * lo hacia mas probable.
     *
     * @return array<int, string>
     */
    private static function categoriasDeterministas(): array
    {
        return app(DeterministicEngine::class)->supportedCategories();
    }

    /**
     * El rango del codigo depende del nivel del conjunto, asi que mostrar los
     * tres rangos siempre obliga al usuario a descartar dos. Se muestra solo
     * el que aplica, con el vocabulario del panel: cliente y marca, no
     * "corporativo" y "override", que no aparecen en ninguna otra pantalla.
     */
    private static function convencionDeCodigo(RuleSet $ruleSet): string
    {
        if ($ruleSet->owner_type === RuleSetOwnerType::Client) {
            return 'Conjunto de cliente: usa el rango 001-499. Ej. COMP-001, PAL-001. '
                .'Las reglas de este conjunto las heredan todas las marcas del cliente.';
        }

        return 'Conjunto de marca: usa el rango 500-899 para reglas propias (ej. PAL-501) '
            .'y 900-999 cuando la regla anula una heredada del cliente (ej. COPY-901). '
            .'Los códigos 001-499 están reservados al conjunto del cliente.';
    }

    /** @return array<int, string> */
    private static function palettesFor(RuleSet $ruleSet): array
    {
        if ($ruleSet->owner_type !== RuleSetOwnerType::Brand) {
            return [];
        }

        return Palette::query()
            ->where('brand_id', $ruleSet->owner_id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
