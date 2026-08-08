@php
    use App\Enums\Severity;

    $findings = $run?->findings ?? collect();
    $verdict = $run?->verdict;

    $orden = ['blocking' => 0, 'major' => 1, 'minor' => 2, 'info' => 3];
    $findings = $findings->sortBy(fn ($f) => $orden[$f->severity->value] ?? 9);

    $colores = [
        'blocking' => '#ef4444',
        'major'    => '#f59e0b',
        'minor'    => '#3b82f6',
        'info'     => '#6b7280',
    ];

    /*
     * Estado de CADA regla aplicada, no solo de las que fallaron.
     *
     * Sin esto, la ausencia de hallazgo es ambigua: puede significar que la
     * regla se cumplio o que nunca se evaluo, y en una auditoria son cosas
     * opuestas. "Evalue COMP-001 y cumple" se puede defender; "no aparece
     * COMP-001" no dice nada.
     */
    $meta = (array) ($run?->deterministic_results ?? []);
    $iaCorrio = ($meta['ai_ran'] ?? false) === true;
    $iaSimulada = ($meta['ai_simulated'] ?? false) === true;
    $iaError = $meta['ai_error'] ?? null;

    $porRegla = $findings->groupBy('rule_id');
    $porCodigo = $findings->groupBy('rule_code');

    $origenes = [
        'client' => 'corporativa',
        'brand' => 'de marca',
        'brand_override' => 'de marca, anula la corporativa',
    ];

    $evaluadas = [];
    $resumen = ['incumple' => 0, 'cumple' => 0, 'pendiente' => 0];

    $etiquetasSeveridad = [
        'blocking' => 'bloqueante',
        'major' => 'mayor',
        'minor' => 'menor',
        'info' => 'informativo',
    ];

    foreach (($run?->resolved_rules_snapshot ?? []) as $r) {
        $deLaRegla = collect($porRegla[$r['rule_id'] ?? null] ?? []);

        if ($deLaRegla->isEmpty()) {
            $deLaRegla = collect($porCodigo[$r['code'] ?? null] ?? []);
        }

        $n = $deLaRegla->count();

        $esDeterminista = ($r['type'] ?? '') === 'deterministic';
        $severidadHallada = null;

        if ($n > 0) {
            $estado = 'incumple';

            /*
             * "1 hallazgo" no dice si la pieza se rechaza o si es un detalle
             * menor, y esa es justo la pregunta que se hace quien mira esta
             * tabla. Se muestra la severidad real del hallazgo, que ademas
             * puede diferir de la severidad configurada en la regla: el
             * evaluador de paleta, por ejemplo, la calcula segun cuanta
             * superficie esta fuera.
             */
            $peor = $deLaRegla
                ->sortBy(fn ($f) => $orden[$f->severity->value] ?? 9)
                ->first();

            $severidadHallada = $peor?->severity->value;
            $nombreSeveridad = $etiquetasSeveridad[$severidadHallada] ?? $severidadHallada;

            $detalle = $n === 1
                ? '1 hallazgo '.$nombreSeveridad
                : $n.' hallazgos, el mas grave '.$nombreSeveridad;
        } elseif ($esDeterminista) {
            $estado = 'cumple';
            $detalle = 'medida por codigo, sin desviacion';
        } elseif ($iaSimulada) {
            $estado = 'pendiente';
            $detalle = 'juicio simulado, no real';
        } elseif ($iaCorrio) {
            $estado = 'cumple';
            $detalle = 'juzgada por el modelo, sin observacion';
        } else {
            $estado = 'pendiente';
            $detalle = $iaError ? 'la llamada al modelo fallo' : 'el motor de juicio no se ejecuto';
        }

        $resumen[$estado]++;

        $evaluadas[] = [
            'code' => $r['code'] ?? '?',
            'title' => $r['title'] ?? '',
            'motor' => $esDeterminista ? 'codigo' : 'IA',
            'origen' => $origenes[$r['origin'] ?? ''] ?? ($r['origin'] ?? ''),
            'severidad' => $r['severity'] ?? '',
            'estado' => $estado,
            'detalle' => $detalle,
            'severidad_hallada' => $severidadHallada,
        ];
    }

    $coloresEstado = [
        'incumple' => '#ef4444',
        'cumple' => '#16a34a',
        'pendiente' => '#a16207',
    ];

    $etiquetasEstado = [
        'incumple' => 'Incumple',
        'cumple' => 'Cumple',
        'pendiente' => 'Sin evaluar',
    ];

    /*
     * Encabezado del veredicto.
     *
     * "Rechazado 0.0/100" se lee como "la pieza esta totalmente mal", y casi
     * nunca lo esta: puede tener 27 de 31 reglas cumplidas y un solo problema
     * normativo. Se muestran las dos cosas por separado —por que se rechaza y
     * que tan bien esta hecha— mas el conteo de reglas, que es el contexto que
     * convierte un numero en informacion.
     */
    $totalReglas = count($evaluadas);

    $motivo = null;

    if ($verdict && $verdict->blocking_count > 0) {
        $codigosBloqueantes = $findings
            ->where('severity', Severity::Blocking)
            ->pluck('rule_code')
            ->filter()
            ->unique()
            ->implode(', ');

        $motivo = $verdict->blocking_count === 1
            ? 'por 1 regla bloqueante'
            : 'por '.$verdict->blocking_count.' reglas bloqueantes';

        if ($codigosBloqueantes !== '') {
            $motivo .= ' ('.$codigosBloqueantes.')';
        }
    }

    $partesResumen = [];

    if ($verdict) {
        if ($verdict->blocking_count > 0) {
            $partesResumen[] = $verdict->blocking_count.' bloqueante'.($verdict->blocking_count === 1 ? '' : 's');
        }
        if ($verdict->major_count > 0) {
            $partesResumen[] = $verdict->major_count.' mayor'.($verdict->major_count === 1 ? '' : 'es');
        }
        if ($verdict->minor_count > 0) {
            $partesResumen[] = $verdict->minor_count.' menor'.($verdict->minor_count === 1 ? '' : 'es');
        }
    }

    if ($totalReglas > 0) {
        $partesResumen[] = $resumen['cumple'].' de '.$totalReglas.' reglas cumplidas';
    }

    if ($resumen['pendiente'] > 0) {
        $partesResumen[] = $resumen['pendiente'].' sin evaluar';
    }

    $lineaResumen = implode(' · ', $partesResumen);
@endphp

<style>
    .hz-wrap { display: grid; gap: 1.25rem; }
    @media (min-width: 900px) { .hz-wrap { grid-template-columns: 260px 1fr; align-items: start; } }
    .hz-preview img { width: 100%; border-radius: 0.75rem; display: block; }
    .hz-meta { font-size: 0.75rem; opacity: 0.65; margin-top: 0.5rem; line-height: 1.4; }
    .hz-bloq { display: inline-block; margin-left: 0.4rem; padding: 0.05rem 0.4rem; border-radius: 0.35rem; background: #ef4444; color: #fff; font-size: 0.6rem; font-weight: 700; letter-spacing: 0.04em; vertical-align: middle; }
    .hz-verdict { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin-bottom: 1rem; }
    .hz-score { font-size: 1.5rem; font-weight: 700; }
    .hz-scorelabel { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.55; font-weight: 600; }
    .hz-motivo { font-size: 0.8125rem; opacity: 0.75; }
    .hz-dudoso { display: inline-block; padding: 0.05rem 0.4rem; border-radius: 0.35rem; border: 1px solid currentColor; color: #a16207; font-size: 0.6rem; font-weight: 700; letter-spacing: 0.04em; }
    .hz-nota { font-size: 0.75rem; opacity: 0.6; margin-top: 0.375rem; font-style: italic; }
    .hz-sep { flex: 1 1 0.5rem; min-width: 0.5rem; }
    .hz-resumen { font-size: 0.8125rem; opacity: 0.7; margin-top: -0.5rem; margin-bottom: 1.25rem; }
    .hz-item { border-left: 3px solid; padding: 0.75rem 0 0.75rem 0.875rem; margin-bottom: 1rem; }
    .hz-head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin-bottom: 0.375rem; }
    .hz-tag { font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em;
              padding: 0.125rem 0.5rem; border-radius: 0.375rem; color: #fff; }
    .hz-code { font-family: ui-monospace, monospace; font-size: 0.75rem; opacity: 0.6; }
    .hz-desc { font-size: 0.875rem; line-height: 1.5; }
    .hz-ev { font-family: ui-monospace, monospace; font-size: 0.75rem; opacity: 0.75; margin-top: 0.375rem; }
    .hz-sug { font-size: 0.8125rem; opacity: 0.8; margin-top: 0.375rem; }
    .hz-sug::before { content: "→ "; opacity: 0.5; }
    .hz-chips { display: flex; flex-wrap: wrap; gap: 0.375rem; margin-top: 0.5rem; }
    .hz-chip { font-family: ui-monospace, monospace; font-size: 0.6875rem; padding: 0.125rem 0.5rem;
               border-radius: 0.375rem; border: 1px solid currentColor; opacity: 0.7; }
    .hz-swatch { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 0.25rem;
                 vertical-align: -1px; margin-right: 0.25rem; border: 1px solid rgba(128,128,128,.4); }
    .hz-empty { text-align: center; padding: 2rem 1rem; }
    .hz-sep { margin: 1.5rem 0 .75rem; font-size: .8125rem; font-weight: 600;
              border-top: 1px solid rgba(128,128,128,.2); padding-top: 1rem; }
    .hz-resumen { font-size: .75rem; opacity: .7; margin-bottom: .625rem; }
    .hz-alerta { font-size: .75rem; padding: .5rem .625rem; border-radius: .375rem;
                 background: rgba(161,98,7,.14); margin-bottom: .75rem; line-height: 1.5; }
    .hz-reglas { width: 100%; border-collapse: collapse; font-size: .75rem; }
    .hz-reglas td { padding: .4375rem .5rem; border-bottom: 1px solid rgba(128,128,128,.12);
                    vertical-align: top; }
    .hz-reglas tr:last-child td { border-bottom: none; }
    .hz-estado { font-weight: 600; white-space: nowrap; }
    .hz-motor { font-family: ui-monospace, monospace; opacity: .55; }
</style>

<div class="hz-wrap">
    <div class="hz-preview">
        @if ($url)
            <img src="{{ $url }}" alt="{{ $asset->original_filename }}">
        @endif

        <div class="hz-meta">
            {{ $asset->width }} × {{ $asset->height }} px<br>
            {{ $asset->mime_type }}<br>
            SHA-256 {{ substr($asset->file_hash, 0, 12) }}…

            @if ($run)
                <br>Reglas aplicadas: {{ count($run->resolved_rules_snapshot ?? []) }}
                <br>Huella del conjunto: {{ substr((string) $run->resolution_hash, 0, 12) }}…
            @endif
        </div>

        @if (! empty($asset->extracted_palette))
            <div class="hz-chips">
                @foreach (array_slice($asset->extracted_palette, 0, 6) as $c)
                    <span class="hz-chip">
                        <span class="hz-swatch" style="background: {{ $c['hex'] }}"></span>{{ $c['hex'] }} {{ $c['percent'] }}%
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    <div>
        @if (! $run)
            <div class="hz-empty">Esta pieza todavia no tiene ninguna validacion.</div>
        @else
            <div class="hz-verdict">
                @if ($verdict)
                    <x-filament::badge :color="$verdict->status->color()" size="lg">
                        {{ $verdict->status->label() }}
                    </x-filament::badge>

                    @if ($motivo)
                        <span class="hz-motivo">{{ $motivo }}</span>
                    @endif

                    <span class="hz-sep"></span>

                    <span class="hz-scorelabel">Calidad</span>
                    <span class="hz-score">{{ number_format((float) $verdict->score, 1) }}</span>
                    <span style="opacity:.6; font-size:.8125rem">/ 100</span>
                @else
                    <x-filament::badge color="gray" size="lg">Sin veredicto</x-filament::badge>
                @endif

                @if ($run->status->value === 'failed')
                    <x-filament::badge color="danger">Ejecucion fallida</x-filament::badge>
                @endif
            </div>

            @if ($lineaResumen !== '')
                <div class="hz-resumen">{{ $lineaResumen }}</div>
            @endif

            @if ($run->error_message)
                <div class="hz-ev" style="color:#ef4444">{{ $run->error_message }}</div>
            @endif

            @forelse ($findings as $f)
                @php
                    $c = $colores[$f->severity->value] ?? '#6b7280';

                    $ev = (array) ($f->evidence_data ?? []);
                    $degradadaDe = $ev['downgraded_from'] ?? null;
                    $confianza = $ev['confidence'] ?? null;
                    $dudoso = ($ev['doubtful'] ?? false) === true;

                    $nota = null;

                    if ($degradadaDe !== null) {
                        $nota = 'La regla la declara '.($etiquetasSeveridad[$degradadaDe] ?? $degradadaDe)
                            .', se registro como '.($etiquetasSeveridad[$f->severity->value] ?? $f->severity->value);

                        if ($confianza !== null) {
                            $nota .= ' porque el modelo declaro confianza '.number_format((float) $confianza, 2);
                        }
                    } elseif ($dudoso && $confianza !== null) {
                        $nota = 'Confianza '.number_format((float) $confianza, 2).': requiere confirmacion humana';
                    }
                @endphp

                <div class="hz-item" style="border-color: {{ $c }}">
                    <div class="hz-head">
                        <span class="hz-tag" style="background: {{ $c }}">{{ $f->severity->label() }}</span>
                        <span class="hz-code">{{ $f->category->label() }}</span>
                        @if ($f->rule_code)
                            <span class="hz-code">· {{ $f->rule_code }}</span>
                        @endif
                        <span class="hz-code">· {{ $f->origin->label() }}</span>
                        @if ($dudoso)
                            <span class="hz-dudoso">DUDOSO</span>
                        @endif
                    </div>

                    <div class="hz-desc">{{ $f->description }}</div>

                    @if ($nota)
                        <div class="hz-nota">{{ $nota }}</div>
                    @endif

                    @if ($f->evidence)
                        <div class="hz-ev">{{ $f->evidence }}</div>
                    @endif

                    @if ($f->suggestion)
                        <div class="hz-sug">{{ $f->suggestion }}</div>
                    @endif

                    @if (! empty($f->evidence_data['detected_hex']))
                        <div class="hz-chips">
                            <span class="hz-chip">
                                <span class="hz-swatch" style="background: {{ $f->evidence_data['detected_hex'] }}"></span>
                                detectado {{ $f->evidence_data['detected_hex'] }}
                            </span>
                            @if (! empty($f->evidence_data['nearest_hex']))
                                <span class="hz-chip">
                                    <span class="hz-swatch" style="background: {{ $f->evidence_data['nearest_hex'] }}"></span>
                                    autorizado {{ $f->evidence_data['nearest_hex'] }}
                                </span>
                            @endif
                            @if (isset($f->evidence_data['delta_e']))
                                <span class="hz-chip">ΔE {{ $f->evidence_data['delta_e'] }}</span>
                            @endif
                            @if (isset($f->evidence_data['tolerance']))
                                <span class="hz-chip">tolerancia {{ $f->evidence_data['tolerance'] }}</span>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div class="hz-empty">
                    <p><strong>Ningun hallazgo.</strong></p>
                    <p style="opacity:.6; font-size:.875rem; margin-top:.25rem">
                        La pieza cumple todas las reglas deterministas evaluadas.
                    </p>
                </div>
            @endforelse

            @if (count($evaluadas) > 0)
                <div class="hz-sep">Reglas aplicadas en esta validacion</div>

                <div class="hz-resumen">
                    {{ count($evaluadas) }} reglas efectivas ·
                    {{ $resumen['incumple'] }} incumplidas ·
                    {{ $resumen['cumple'] }} cumplidas ·
                    {{ $resumen['pendiente'] }} sin evaluar
                </div>

                @if ($resumen['pendiente'] > 0)
                    <div class="hz-alerta">
                        Este veredicto <strong>no cubre {{ $resumen['pendiente'] }} regla(s)</strong>.
                        Una pieza puede salir aprobada y aun asi incumplir alguna de ellas.
                    </div>
                @endif

                <table class="hz-reglas">
                    @foreach ($evaluadas as $e)
                        <tr>
                            <td class="hz-motor">{{ $e['code'] }}</td>
                            <td>
                                {{ $e['title'] }}
                                <div class="hz-motor">{{ $e['origen'] }} · evalua {{ $e['motor'] }}</div>
                            </td>
                            <td class="hz-estado" style="color: {{ $coloresEstado[$e['estado']] }}">
                                {{ $etiquetasEstado[$e['estado']] }}
                                @if ($e['severidad_hallada'] === 'blocking')
                                    <span class="hz-bloq">BLOQUEANTE</span>
                                @endif
                                <div class="hz-motor" style="font-weight:400">{{ $e['detalle'] }}</div>
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endif
    </div>
</div>
