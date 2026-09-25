<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assets\Tables;

use App\Enums\Severity;
use App\Enums\VerdictStatus;
use App\Jobs\RunValidation;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\HumanReview;
use App\Models\Submission;

use App\Models\ValidationRun;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Se carga por adelantado lo que pinta cada fila. Sin esto, con el
            // modo estricto de Eloquent activado la pagina falla en vez de
            // degradarse en silencio, que es justo lo que queremos.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'brand.client',
                    'submission.user',
                    'latestRun.verdict',
                    'latestRun.findings',
                    'latestRun.humanReviews',
                ])
                ->withCount('validationRuns'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('storage_path')
                    ->label('Pieza')
                    // URL autenticada: el disco de piezas es privado.
                    ->getStateUsing(fn (Asset $record): ?string => $record->url())
                    ->height(52)
                    ->square(),

                TextColumn::make('original_filename')
                    ->label('Archivo')
                    ->searchable()
                    ->limit(38)
                    ->description(fn (Asset $r): string => sprintf(
                        '%s x %s px - %s',
                        $r->width ?? '?',
                        $r->height ?? '?',
                        self::humanBytes((int) $r->file_size),
                    )),

                TextColumn::make('brand.name')
                    ->label('Marca')
                    ->sortable()
                    ->description(fn (Asset $r): string => $r->brand?->client?->name ?? ''),

                // No es ordenable: el nombre vive en la carga, dos saltos de
                // relacion mas alla, y ordenar por ahi obliga a un join que
                // encarece cada pagina. Para agrupar por persona esta el
                // filtro, que hace el mismo trabajo y cuesta una consulta.
                TextColumn::make('disenador')
                    ->label('Diseñador')
                    ->state(fn (Asset $r): string => $r->submission?->user?->name ?? '—')
                    ->description(fn (Asset $r): ?string => $r->submission?->campaign)
                    ->toggleable(),

                TextColumn::make('veredicto')
                    ->label('Veredicto')
                    ->badge()
                    ->state(fn (Asset $r): string => self::veredictoEfectivo($r)?->label() ?? 'Sin veredicto')
                    ->color(fn (Asset $r): string => self::veredictoEfectivo($r)?->color() ?? 'gray')
                    ->description(fn (Asset $r): ?string => self::notaDeRevision($r)),

                TextColumn::make('puntaje')
                    ->label('Puntaje')
                    ->alignCenter()
                    ->state(fn (Asset $r): string => $r->latestRun?->verdict?->score !== null
                        ? number_format((float) $r->latestRun->verdict->score, 1)
                        : '-'),

                TextColumn::make('hallazgos')
                    ->label('Hallazgos')
                    ->alignCenter()
                    ->badge()
                    ->state(fn (Asset $r): string => self::resumenHallazgos($r))
                    ->color(fn (Asset $r): string => self::colorHallazgos($r)),

                TextColumn::make('origen')
                    ->label('Origen')
                    ->badge()
                    ->state(fn (Asset $r): string => self::etiquetaOrigen($r->submission?->source))
                    ->color(fn (Asset $r): string => match ($r->submission?->source) {
                        'figma' => 'info',
                        'api' => 'info',
                        'panel_rapido' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('modelo')
                    ->label('Modelo')
                    ->state(fn (Asset $r): string => self::nombreModelo($r->latestRun?->model_identifier) ?? '-')
                    ->description(fn (Asset $r): string => self::notaModelo($r))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Cargada')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Asset $r): string => $r->created_at?->diffForHumans() ?? '')
                    ->sortable(),

                TextColumn::make('ultima_validacion')
                    ->label('Ultima validacion')
                    ->state(fn (Asset $r): string => $r->latestRun?->created_at?->format('d/m/Y H:i') ?? '-')
                    ->description(fn (Asset $r): string => ($n = (int) ($r->validation_runs_count ?? 0)) > 1
                        ? ($n - 1).' revalidacion'.($n > 2 ? 'es' : '')
                        : 'al cargar')
                    // Subconsulta correlacionada: no hay columna que ordenar.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        ValidationRun::query()
                            ->select('created_at')
                            ->whereColumn('validation_runs.asset_id', 'assets.id')
                            ->orderByDesc('created_at')
                            ->limit(1),
                        $direction,
                    ))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('brand_id')
                    ->label('Marca')
                    ->options(fn (): array => Brand::query()
                        ->whereIn('id', auth()->user()->accessibleBrandIds())
                        ->with('client')
                        ->get()
                        ->sortBy(fn (Brand $b): string => $b->fullName())
                        ->mapWithKeys(fn (Brand $b): array => [$b->id => $b->fullName()])
                        ->all())
                    ->searchable(),

                // El veredicto vive en la ejecucion mas reciente, no en la
                // pieza. Se filtra con existencia sobre esa relacion para que
                // una pieza revalidada se juzgue por su resultado vigente.
                SelectFilter::make('veredicto')
                    ->label('Veredicto')
                    ->options([
                        VerdictStatus::Approved->value => 'Aprobado',
                        VerdictStatus::ApprovedWithObservations->value => 'Con observaciones',
                        VerdictStatus::Rejected->value => 'Rechazado',
                        VerdictStatus::RequiresReview->value => 'Requiere revision',
                        VerdictStatus::NotEvaluated->value => 'Sin evaluar',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'latestRun.verdict',
                            fn (Builder $q): Builder => $q->where('status', $data['value'])
                        );
                    }),

                // El nombre esta en la carga, no en la pieza, asi que se
                // filtra por existencia sobre esa relacion.
                SelectFilter::make('disenador')
                    ->label('Diseñador')
                    ->options(fn (): array => Submission::cargadores())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'submission',
                            fn (Builder $q): Builder => $q->where('user_id', $data['value'])
                        );
                    }),

                SelectFilter::make('origen')
                    ->label('Origen')
                    ->options([
                        'panel' => 'Carga desde el panel',
                        'panel_rapido' => 'Validacion rapida',
                        'api' => 'API',
                        'figma' => 'Plugin de Figma',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'submission',
                            fn (Builder $q): Builder => $q->where('source', $data['value'])
                        );
                    }),

                Filter::make('bloqueantes')
                    ->label('Solo con hallazgos bloqueantes')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'latestRun.findings',
                        fn (Builder $q): Builder => $q->where('severity', Severity::Blocking->value)
                    )),

                Filter::make('sin_ia')
                    ->label('Sin juicio de IA')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'latestRun',
                        fn (Builder $q): Builder => $q->whereNull('model_identifier')
                    )),
            ])
            ->recordActions([
                Action::make('hallazgos')
                    ->label('Hallazgos')
                    ->icon('heroicon-o-magnifying-glass')
                    ->modalHeading(fn (Asset $record): string => "Hallazgos de {$record->original_filename}")
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (Asset $record) => view('filament.modals.hallazgos', [
                        'asset' => $record,
                        'run' => $record->latestRun()->with(['findings', 'verdict'])->first(),
                        'url' => self::previewUrl($record),
                    ])),

                Action::make('historial')
                    ->label(fn (Asset $record): string => ($n = (int) ($record->validation_runs_count ?? 0)) > 1
                        ? "Historial ({$n})"
                        : 'Historial')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Asset $record): string => "Historial de {$record->original_filename}")
                    ->modalWidth('6xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (Asset $record) => view('filament.modals.historial', [
                        'asset' => $record,
                    ])),

                Action::make('revalidar')
                    ->label('Revalidar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Se creara una ejecucion nueva con las reglas publicadas vigentes. Las anteriores se conservan.')
                    ->authorize('validar')
                    ->action(function (Asset $record): void {
                        RunValidation::dispatch($record, auth()->id());

                        Notification::make()->title('Validacion lanzada')->success()->send();
                    }),

                ActionGroup::make(
                    collect(config('ai.available_models', []))
                        ->map(fn (string $etiqueta, string $id): Action => Action::make('modelo_'.Str::slug($id, '_'))
                            ->label($id === (string) config('ai.model') ? $etiqueta.'   [configurado]' : $etiqueta)
                            ->requiresConfirmation()
                            ->modalHeading('Validar con '.strtok($etiqueta, ' '))
                            ->modalDescription("Se creara una ejecucion nueva evaluada con {$id}, sobre las mismas reglas vigentes. Abre Historial para compararlas.")
                            ->authorize('validar')
                            ->action(function (Asset $record) use ($id, $etiqueta): void {
                                RunValidation::dispatch($record, auth()->id(), $id);

                                Notification::make()
                                    ->title('Validada con '.strtok($etiqueta, ' '))
                                    ->success()
                                    ->send();
                            }))
                        ->values()
                        ->all()
                )
                    ->label('Validar con otro modelo')
                    ->icon('heroicon-o-beaker')
                    ->color('gray')
                    ->button()
                    // Solo super_admin: el auditor tiene alcance global de
                    // lectura, pero no gasta ni valida.
                    ->visible(fn (): bool => (bool) (auth()->user()?->hasRole('super_admin') ?? false)),
            ])
            ->emptyStateHeading('Sin piezas')
            ->emptyStateDescription('Las piezas aparecen aqui al cargarlas, validarlas rapido o enviarlas por la API.');
    }

    /**
     * La revision humana de la ultima ejecucion, si la hubo.
     *
     * Se lee de la relacion ya cargada y no con una consulta nueva: en una
     * tabla de cincuenta filas, resolverlo por fila serian cincuenta consultas.
     */
    private static function revisionDe(Asset $asset): ?HumanReview
    {
        return $asset->latestRun?->humanReviews->first();
    }

    /**
     * El veredicto que vale: el de la persona si reviso, el de la maquina si no.
     *
     * Una pieza rechazada por el motor y aprobada despues por un revisor debe
     * aparecer como aprobada. Mostrar el veredicto de la maquina cuando ya hay
     * una decision humana encima convierte el listado en una fuente que
     * contradice al expediente.
     */
    private static function veredictoEfectivo(Asset $asset): ?VerdictStatus
    {
        return self::revisionDe($asset)?->final_verdict
            ?? $asset->latestRun?->verdict?->status;
    }

    /**
     * La linea de abajo del veredicto.
     *
     * Distingue tres situaciones que a simple vista se confundirian: nadie
     * reviso, alguien reviso y estuvo de acuerdo, o alguien reviso y cambio la
     * conclusion. Solo en el tercer caso se nombra el veredicto de la maquina,
     * porque es el unico donde la diferencia importa.
     */
    private static function notaDeRevision(Asset $asset): ?string
    {
        $revision = self::revisionDe($asset);

        if ($revision === null) {
            return null;
        }

        if (! $revision->overrode_machine) {
            return 'Revisado y confirmado';
        }

        return 'Revisado · la maquina dijo '.$revision->machine_verdict->label();
    }

    private static function etiquetaOrigen(?string $source): string
    {
        return match ($source) {
            'figma' => 'Figma',
            'api' => 'API',
            'panel_rapido' => 'Rapida',
            'panel', null => 'Panel',
            default => $source,
        };
    }

    private static function nombreModelo(?string $identificador): ?string
    {
        if (blank($identificador)) {
            return null;
        }

        return str_replace('claude-', '', (string) preg_replace('/-[0-9]{8}$/', '', $identificador));
    }

    private static function notaModelo(Asset $asset): string
    {
        $meta = (array) ($asset->latestRun?->deterministic_results ?? []);

        if (($meta['ai_simulated'] ?? false) === true) {
            return 'simulado';
        }

        if (($meta['ai_ran'] ?? false) !== true) {
            return 'sin IA';
        }

        return ($meta['model_requested'] ?? null) !== null ? 'elegido a mano' : 'configurado';
    }

    private static function resumenHallazgos(Asset $asset): string
    {
        $findings = $asset->latestRun?->findings;

        if ($findings === null) {
            return '-';
        }

        if ($findings->isEmpty()) {
            return 'Ninguno';
        }

        $siglas = [
            [Severity::Blocking, 'B'],
            [Severity::Major, 'M'],
            [Severity::Minor, 'm'],
            [Severity::Info, 'i'],
        ];

        $partes = [];

        foreach ($siglas as [$severidad, $sigla]) {
            $n = $findings->where('severity', $severidad)->count();

            if ($n > 0) {
                $partes[] = $n.$sigla;
            }
        }

        return $partes === [] ? 'Ninguno' : implode(' - ', $partes);
    }

    private static function colorHallazgos(Asset $asset): string
    {
        $findings = $asset->latestRun?->findings;

        if ($findings === null || $findings->isEmpty()) {
            return 'gray';
        }

        return match (true) {
            $findings->where('severity', Severity::Blocking)->isNotEmpty() => 'danger',
            $findings->where('severity', Severity::Major)->isNotEmpty() => 'warning',
            default => 'info',
        };
    }

    public static function previewUrl(Asset $asset): ?string
    {
        return $asset->url();
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 2).' MB'
            : round($bytes / 1024).' KB';
    }
}
