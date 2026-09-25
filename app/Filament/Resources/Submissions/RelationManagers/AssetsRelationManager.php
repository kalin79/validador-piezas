<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\RelationManagers;

use App\Enums\Severity;
use App\Enums\ValidationStatus;
use App\Enums\VerdictStatus;
use App\Jobs\RunValidation;
use App\Models\Asset;
use App\Models\ValidationRun;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    protected static ?string $title = 'Piezas y resultados';

    public function table(Table $table): Table
    {
        return $table
            // Se refresca sola mientras haya validaciones en curso.
            ->poll(fn (): ?string => \App\Support\Refresco::mientrasHayaValidaciones())
            ->recordTitleAttribute('original_filename')
            // Se cargan por adelantado la ultima ejecucion con su veredicto y
            // sus hallazgos: sin esto, cada fila dispara tres consultas y con
            // el modo estricto de Eloquent activado la pagina falla.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'latestRun.verdict',
                    'latestRun.findings',
                ])
                // Cuenta agregada en la misma consulta. Sirve para rotular el
                // boton de historial sin traer las ejecuciones completas.
                ->withCount('validationRuns'))
            ->columns([
                ImageColumn::make('storage_path')
                    ->label('Pieza')
                    // URL autenticada: el disco de piezas es privado.
                    ->getStateUsing(fn (Asset $record): ?string => $record->url())
                    ->height(56)
                    ->square(),

                TextColumn::make('original_filename')
                    ->label('Archivo')
                    ->searchable()
                    ->description(fn (Asset $r): string => sprintf(
                        '%s × %s px · %s',
                        $r->width ?? '?',
                        $r->height ?? '?',
                        self::humanBytes((int) $r->file_size),
                    )),

                TextColumn::make('veredicto')
                    ->label('Veredicto')
                    ->badge()
                    ->state(fn (Asset $record): string => $record->latestRun?->verdict?->status->label() ?? 'Sin veredicto')
                    ->color(fn (Asset $record): string => $record->latestRun?->verdict?->status->color() ?? 'gray'),

                TextColumn::make('puntaje')
                    ->label('Puntaje')
                    ->alignCenter()
                    ->state(fn (Asset $record): string => $record->latestRun?->verdict?->score !== null
                        ? number_format((float) $record->latestRun->verdict->score, 1)
                        : '—'),

                // Se usa state() y no counts(): counts() no admite relaciones
                // anidadas, construye withCount('latestRun.findings') y Eloquent
                // lo interpreta como un metodo con ese nombre literal.
                TextColumn::make('hallazgos')
                    ->label('Hallazgos')
                    ->alignCenter()
                    ->badge()
                    ->state(fn (Asset $record): string => self::resumenHallazgos($record))
                    ->color(fn (Asset $record): string => self::colorHallazgos($record)),

                TextColumn::make('costo')
                    ->label('Costo IA')
                    ->alignEnd()
                    ->state(fn (Asset $record): string => $record->latestRun?->cost_usd !== null
                        ? '$'.number_format((float) $record->latestRun->cost_usd, 4)
                        : '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (Asset $record): string => $record->latestRun?->status->label() ?? 'Sin validar')
                    ->color(fn (Asset $record): string => match ($record->latestRun?->status) {
                        ValidationStatus::Completed => 'success',
                        ValidationStatus::Failed => 'danger',
                        ValidationStatus::Running => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(),

                // Ahora que el modelo puede variar entre ejecuciones, saber con
                // cual se juzgo deja de ser un detalle de configuracion y pasa
                // a ser parte del resultado.
                TextColumn::make('modelo')
                    ->label('Modelo')
                    ->state(fn (Asset $record): string => self::nombreModelo($record->latestRun?->model_identifier) ?? '—')
                    ->description(fn (Asset $record): string => self::notaModelo($record))
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Cargada')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Asset $record): string => $record->created_at?->diffForHumans() ?? '')
                    ->sortable(),

                // No se usa updated_at de la pieza: validar no la modifica, crea
                // filas en validation_runs. La columna diria "hace 3 dias" para
                // una pieza revalidada hace un minuto, que es peor que no tenerla.
                TextColumn::make('ultima_validacion')
                    ->label('Ultima validacion')
                    ->state(fn (Asset $record): string => \App\Support\Fecha::local($record->latestRun?->created_at)?->format('d/m/Y H:i') ?? '—')
                    ->description(fn (Asset $record): string => self::descripcionUltimaValidacion($record))
                    // Ordena por la fecha de la ultima ejecucion con una
                    // subconsulta correlacionada: no hay columna que ordenar.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        ValidationRun::query()
                            ->select('created_at')
                            ->whereColumn('validation_runs.asset_id', 'assets.id')
                            ->orderByDesc('created_at')
                            ->limit(1),
                        $direction,
                    )),
            ])
            // Lo ultimo cargado arriba. En una carga con varias versiones de la
            // misma pieza, la corregida es la que importa.
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('hallazgos')
                    ->label('Ver hallazgos')
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

                // El historial no se calcula aqui: la consulta vive en la vista
                // y solo se ejecuta cuando alguien abre el modal. Cargar todas
                // las ejecuciones de cada fila para una columna que casi nadie
                // mira seria pagar N consultas por una respuesta eventual.
                Action::make('historial')
                    // El numero va en la etiqueta y no en un badge: badge() no
                    // esta garantizado en acciones de tabla y un metodo
                    // inexistente tumba la pagina entera.
                    ->label(fn (Asset $record): string => ($n = (int) ($record->validation_runs_count ?? 0)) > 1
                        ? "Historial ({$n})"
                        : 'Historial')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Asset $record): string => "Historial de validaciones de {$record->original_filename}")
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
                    ->modalDescription('Se creara una ejecucion nueva con las reglas publicadas vigentes y el modelo configurado. Las anteriores se conservan.')
                    ->authorize('validar')
                    ->action(function (Asset $record): void {
                        RunValidation::dispatch($record, auth()->id());

                        Notification::make()
                            ->title('Validacion lanzada')
                            ->success()
                            ->send();
                    }),

                // Un grupo con una accion por modelo, generadas desde
                // config('ai.available_models'). Se resolvio asi y no con un
                // desplegable dentro de un modal para no depender de la API de
                // formularios en acciones, que es la que mas cambio entre
                // versiones de Filament.
                //
                // Solo para acceso global: elegir el modelo es elegir con que
                // rigor se juzga. Si cualquiera pudiera hacerlo, bastaria
                // reintentar con otro modelo hasta que la pieza pase, y el
                // veredicto dejaria de significar algo.
                // El grupo se llama por lo que hace, no por lo que se espera
                // lograr con el: lanza una validacion mas con otro modelo. La
                // comparacion la hace la persona, en el historial. Un boton
                // llamado "Comparar" prometeria una pantalla que no existe.
                ActionGroup::make(
                    collect(config('ai.available_models', []))
                        ->map(fn (string $etiqueta, string $id): Action => Action::make('modelo_'.Str::slug($id, '_'))
                            // Se marca cual es el modelo configurado para que
                            // se vea con cual se esta validando hoy sin salir
                            // de la pantalla ni abrir el .env.
                            ->label($id === (string) config('ai.model') ? $etiqueta.'   [configurado]' : $etiqueta)
                            ->requiresConfirmation()
                            ->modalHeading('Validar con '.strtok($etiqueta, ' '))
                            ->modalDescription(fn (Asset $record): string => sprintf(
                                'Se creara una ejecucion nueva evaluada con %s, sobre las mismas reglas vigentes. '
                                .'La ultima validacion de esta pieza uso %s. '
                                .'Ninguna se reemplaza: abre Historial para verlas una al lado de la otra.',
                                $id,
                                self::nombreModelo($record->latestRun?->model_identifier) ?? 'otro modelo',
                            ))
                            ->modalSubmitActionLabel('Validar con '.strtok($etiqueta, ' '))
                            ->authorize('validar')
                            ->action(function (Asset $record) use ($id, $etiqueta): void {
                                RunValidation::dispatch($record, auth()->id(), $id);

                                Notification::make()
                                    ->title('Validada con '.strtok($etiqueta, ' '))
                                    ->body('Abre Historial en esta pieza para comparar contra las ejecuciones anteriores.')
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
            ->emptyStateDescription('Las piezas se agregan al crear la carga.');
    }

    /**
     * Desglose por severidad en vez de un total: "2B · 1M" comunica mucho mas
     * que "3", y es la diferencia entre una pieza rechazada y una aprobada con
     * observaciones.
     */
    private static function resumenHallazgos(Asset $asset): string
    {
        $findings = $asset->latestRun?->findings;

        if ($findings === null) {
            return '—';
        }

        if ($findings->isEmpty()) {
            return 'Ninguno';
        }

        // Pares y no un mapa: en PHP las claves de un arreglo solo pueden ser
        // enteros o cadenas, nunca un enum.
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

        return $partes === [] ? 'Ninguno' : implode(' · ', $partes);
    }

    /**
     * Distingue la validacion de ingreso de las revalidaciones posteriores.
     *
     * Una pieza con una sola ejecucion no tiene historia que contar; una con
     * varias si, y conviene que se note desde el listado.
     */
    private static function descripcionUltimaValidacion(Asset $asset): string
    {
        $n = (int) ($asset->validation_runs_count ?? 0);

        if ($n === 0) {
            return 'sin validar';
        }

        $cuando = $asset->latestRun?->created_at?->diffForHumans() ?? '';

        return $n === 1
            ? trim("{$cuando} · al cargar")
            : trim("{$cuando} · ".($n - 1).' revalidacion'.($n > 2 ? 'es' : ''));
    }

    /**
     * Nombre corto del modelo: se quita el prefijo del proveedor y la fecha del
     * identificador, que en una tabla solo ocupan espacio.
     */
    private static function nombreModelo(?string $identificador): ?string
    {
        if (blank($identificador)) {
            return null;
        }

        return str_replace('claude-', '', (string) preg_replace('/-[0-9]{8}$/', '', $identificador));
    }

    /**
     * Distingue un juicio real de uno simulado o inexistente. Un puntaje
     * obtenido con el driver de prueba no significa lo mismo que uno real, y
     * desde el listado deben verse distintos.
     */
    private static function notaModelo(Asset $asset): string
    {
        $meta = (array) ($asset->latestRun?->deterministic_results ?? []);

        if (($meta['ai_simulated'] ?? false) === true) {
            return 'simulado';
        }

        if (($meta['ai_ran'] ?? false) !== true) {
            return 'sin IA';
        }

        return ($meta['model_requested'] ?? null) !== null
            ? 'elegido a mano'
            : 'configurado';
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
