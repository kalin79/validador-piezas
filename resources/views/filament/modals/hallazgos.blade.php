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

    $etiquetasSeveridad = [
        'blocking' => 'bloqueante',
        'major' => 'mayor',
        'minor' => 'menor',
        'info' => 'informativo',
    ];

    $origenes = [
        'client' => 'corporativa',
        'brand' => 'de marca',
        'brand_override' => 'de marca, anula la corporativa',
    ];

    // Una sola fuente de verdad para el estado de cada regla. "Cumple" solo
    // aparece si la cobertura registrada dice que la regla se evaluo.
    $reporteReglas = \App\Services\Validation\RuleStatusReport::for($run);
    $resumen = $reporteReglas['resumen'];
    $evaluadas = [];

    foreach ($reporteReglas['rules'] as $r) {
        $evaluadas[] = [
            'code' => $r['code'],
            'title' => $r['title'],
            'motor' => $r['type'] === 'deterministic' ? 'codigo' : 'IA',
            'origen' => $origenes[$r['origin']] ?? $r['origin'],
            'severidad' => $r['severity'],
            'estado' => $r['estado'],
            'detalle' => $r['detalle'],
            'severidad_hallada' => $r['severidad_hallada'],
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
     * Dos datos distintos, cada uno con su lugar: el ESTADO (puede publicarse o
     * no, y por que) y la CALIDAD (que tan bien esta hecha). "Rechazado 0/100"
     * se leia como "todo esta mal" cuando la pieza cumplia 27 de 31 reglas.
     */
    $totalReglas = count($evaluadas);

    $estadoValor = $verdict?->status->value;

    $paletaEstado = [
        'approved' => ['#16a34a', 'rgba(22,163,74,.12)'],
        'approved_with_observations' => ['#d97706', 'rgba(217,119,6,.12)'],
        'rejected' => ['#dc2626', 'rgba(220,38,38,.10)'],
        'requires_review' => ['#2563eb', 'rgba(37,99,235,.10)'],
        'not_evaluated' => ['#6b7280', 'rgba(107,114,128,.12)'],
    ];

    [$colorEstado, $fondoEstado] = $paletaEstado[$estadoValor] ?? ['#6b7280', 'rgba(107,114,128,.12)'];

    $codigosBloqueantes = $findings
        ->where('severity', Severity::Blocking)
        ->pluck('rule_code')
        ->filter()
        ->unique()
        ->values();

    $titular = match ($estadoValor) {
        'rejected' => $codigosBloqueantes->isNotEmpty()
            ? 'No puede publicarse: incumple '.($codigosBloqueantes->count() === 1 ? 'una regla bloqueante' : $codigosBloqueantes->count().' reglas bloqueantes').' ('.$codigosBloqueantes->implode(', ').').'
            : 'No puede publicarse: la calidad quedo por debajo del minimo aceptable.',
        'requires_review' => 'Una persona debe revisarla: '.$resumen['pendiente'].' regla(s) no se pudieron verificar.',
        'not_evaluated' => 'No se pudo evaluar: ninguna regla quedo verificada.',
        'approved_with_observations' => 'Puede publicarse, con observaciones a corregir.',
        'approved' => 'Puede publicarse: cumple todas las reglas verificadas.',
        default => 'Sin veredicto.',
    };

    $puntaje = $verdict?->score !== null ? (float) $verdict->score : null;
    $mostrarPuntaje = $puntaje !== null && $estadoValor !== 'not_evaluated';

    // Anillo del puntaje (SVG). r = 42 -> circunferencia 263.89.
    $circ = 263.89;
    $trazo = $mostrarPuntaje ? round($circ * max(0, min(100, $puntaje)) / 100, 2) : 0;
    $colorPuntaje = ! $mostrarPuntaje ? '#9ca3af' : ($puntaje >= 90 ? '#16a34a' : ($puntaje >= 50 ? '#d97706' : '#dc2626'));

    // Barra de cobertura: cumple / incumple / pendiente.
    $pct = fn (int $n): float => $totalReglas > 0 ? round($n * 100 / $totalReglas, 2) : 0;

    /*
     * Hallazgos agrupados por regla.
     *
     * La regla de paleta produce uno por color: mostrados sueltos, una sola
     * regla ocupaba media pantalla y enterraba el bloqueante. Agrupados, cada
     * regla es una tarjeta con su peor severidad y el detalle adentro. Coincide
     * con el calculo del puntaje, que tambien cuenta una vez por regla.
     */
    $grupos = [];

    foreach ($findings as $f) {
        $clave = $f->rule_code ?: 'sin-regla-'.$f->id;

        if (! isset($grupos[$clave])) {
            $grupos[$clave] = [
                'codigo' => $f->rule_code,
                'categoria' => $f->category->label(),
                'origen' => $f->origin->label(),
                'peor' => $f->severity,
                'items' => [],
            ];
        }

        if (($orden[$f->severity->value] ?? 9) < ($orden[$grupos[$clave]['peor']->value] ?? 9)) {
            $grupos[$clave]['peor'] = $f->severity;
        }

        $grupos[$clave]['items'][] = $f;
    }

    uasort($grupos, fn ($a, $b) => ($orden[$a['peor']->value] ?? 9) <=> ($orden[$b['peor']->value] ?? 9));

    $etiquetasEstado['pendiente'] = 'Sin verificar';
    $coloresEstado['pendiente'] = '#2563eb';
@endphp

<style>
    .hz { --hz-borde: rgba(120,120,120,.18); --hz-suave: rgba(120,120,120,.06); --hz-texto2: rgba(100,100,110,1); }
    .dark .hz { --hz-borde: rgba(255,255,255,.10); --hz-suave: rgba(255,255,255,.04); --hz-texto2: rgba(170,170,180,1); }
    .hz * { box-sizing: border-box; }
    .hz-wrap { display: grid; gap: 1.5rem; }
    @media (min-width: 900px) { .hz-wrap { grid-template-columns: 240px minmax(0, 1fr); align-items: start; } }
    .hz-col { min-width: 0; }
    .hz-texto { overflow-wrap: anywhere; word-break: break-word; }

    /* Columna izquierda */
    .hz-preview img { width: 100%; border-radius: .875rem; display: block; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
    .hz-meta { font-size: .75rem; color: var(--hz-texto2); margin-top: .75rem; line-height: 1.6; }
    .hz-meta b { font-weight: 600; }
    .hz-chips { display: flex; flex-wrap: wrap; gap: .375rem; margin-top: .625rem; }
    .hz-chip { display: inline-flex; align-items: center; gap: .3rem; font-family: ui-monospace, monospace; font-size: .6875rem;
               padding: .15rem .5rem; border-radius: 999px; border: 1px solid var(--hz-borde); background: var(--hz-suave); }
    .hz-swatch { display: inline-block; width: .7rem; height: .7rem; border-radius: 999px; border: 1px solid rgba(128,128,128,.35); }

    /* Tarjeta de veredicto */
    .hz-hero { display: flex; gap: 1.25rem; align-items: center; padding: 1.125rem 1.25rem; border-radius: 1rem;
               border: 1px solid var(--hz-borde); }
    .hz-ring { position: relative; width: 104px; height: 104px; flex: 0 0 104px; }
    .hz-ring svg { transform: rotate(-90deg); }
    .hz-ring-num { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .hz-ring-num strong { font-size: 1.75rem; font-weight: 800; line-height: 1; }
    .hz-ring-num span { font-size: .625rem; letter-spacing: .08em; text-transform: uppercase; color: var(--hz-texto2); margin-top: .2rem; }
    .hz-hero-body { min-width: 0; flex: 1; }
    .hz-pill { display: inline-flex; align-items: center; gap: .375rem; font-size: .75rem; font-weight: 700; letter-spacing: .03em;
               text-transform: uppercase; padding: .25rem .625rem; border-radius: 999px; color: #fff; }
    .hz-titular { font-size: .9375rem; font-weight: 600; margin-top: .5rem; line-height: 1.45; }
    .hz-explica { font-size: .75rem; color: var(--hz-texto2); margin-top: .375rem; line-height: 1.5; }

    /* Indicadores */
    .hz-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .5rem; margin-top: .875rem; }
    @media (max-width: 640px) { .hz-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    .hz-stat { border: 1px solid var(--hz-borde); border-radius: .75rem; padding: .5rem .625rem; background: var(--hz-suave); }
    .hz-stat strong { display: block; font-size: 1.125rem; font-weight: 800; line-height: 1.2; }
    .hz-stat span { font-size: .6875rem; color: var(--hz-texto2); }

    /* Cobertura */
    .hz-cob { margin-top: .875rem; }
    .hz-cob-bar { display: flex; height: .5rem; border-radius: 999px; overflow: hidden; background: var(--hz-suave); }
    .hz-cob-bar i { display: block; height: 100%; }
    .hz-cob-ley { display: flex; flex-wrap: wrap; gap: .875rem; margin-top: .375rem; font-size: .6875rem; color: var(--hz-texto2); }
    .hz-cob-ley b { display: inline-block; width: .5rem; height: .5rem; border-radius: 999px; margin-right: .3rem; }

    .hz-alerta { font-size: .8125rem; padding: .625rem .875rem; border-radius: .75rem; margin-top: .875rem; line-height: 1.5;
                 background: rgba(37,99,235,.08); border: 1px solid rgba(37,99,235,.25); }
    .hz-error { font-size: .8125rem; padding: .625rem .875rem; border-radius: .75rem; margin-top: .875rem;
                background: rgba(220,38,38,.08); border: 1px solid rgba(220,38,38,.25); color: #b91c1c; }

    /* Secciones */
    .hz-seccion { margin: 1.5rem 0 .75rem; display: flex; align-items: baseline; gap: .5rem; }
    .hz-seccion h3 { font-size: .875rem; font-weight: 700; }
    .hz-seccion span { font-size: .75rem; color: var(--hz-texto2); }

    /* Tarjetas de hallazgo */
    .hz-card { border: 1px solid var(--hz-borde); border-left-width: 4px; border-radius: .75rem; padding: .875rem 1rem; margin-bottom: .75rem; }
    .hz-head { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-bottom: .5rem; }
    .hz-tag { font-size: .6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: .15rem .5rem; border-radius: .375rem; color: #fff; }
    .hz-code { font-family: ui-monospace, monospace; font-size: .75rem; font-weight: 600; }
    .hz-meta2 { font-size: .75rem; color: var(--hz-texto2); }
    .hz-dudoso { font-size: .625rem; font-weight: 700; letter-spacing: .04em; padding: .1rem .4rem; border-radius: .35rem; border: 1px solid #a16207; color: #a16207; }
    .hz-sub { padding: .5rem 0; }
    .hz-sub + .hz-sub { border-top: 1px dashed var(--hz-borde); }
    .hz-desc { font-size: .875rem; line-height: 1.5; }
    .hz-ev { font-family: ui-monospace, monospace; font-size: .75rem; margin-top: .375rem; padding: .375rem .5rem; border-radius: .375rem; background: var(--hz-suave); }
    .hz-sug { font-size: .8125rem; margin-top: .375rem; color: var(--hz-texto2); }
    .hz-sug::before { content: "→ "; }
    .hz-nota { font-size: .75rem; color: var(--hz-texto2); margin-top: .375rem; font-style: italic; }

    /* Tabla de reglas */
    .hz-reglas { width: 100%; border-collapse: collapse; font-size: .75rem; table-layout: fixed; }
    .hz-reglas col.c1 { width: 90px; } .hz-reglas col.c3 { width: 38%; }
    .hz-reglas td { padding: .5rem; border-bottom: 1px solid var(--hz-borde); vertical-align: top; overflow-wrap: anywhere; }
    .hz-reglas tr:last-child td { border-bottom: none; }
    .hz-estado { display: inline-block; font-weight: 700; font-size: .6875rem; padding: .1rem .45rem; border-radius: 999px; color: #fff; }
    .hz-motor { font-family: ui-monospace, monospace; color: var(--hz-texto2); }
    .hz-empty { text-align: center; padding: 2rem 1rem; border: 1px dashed var(--hz-borde); border-radius: .75rem; }
</style>

<div class="hz">
<div class="hz-wrap">
    {{-- Columna izquierda: la pieza --}}
    <div class="hz-col hz-preview">
        @if ($url)
            <img src="{{ $url }}" alt="{{ $asset->original_filename }}">
        @endif

        <div class="hz-meta hz-texto">
            <b>{{ $asset->width }} × {{ $asset->height }} px</b> · {{ $asset->mime_type }}<br>
            SHA-256 {{ substr($asset->file_hash, 0, 12) }}…
            @if ($run)
                <br>Reglas aplicadas: {{ count($run->resolved_rules_snapshot ?? []) }}
                <br>Conjunto: {{ substr((string) $run->resolution_hash, 0, 12) }}…
                @if ($run->model_identifier)
                    <br>Modelo: {{ $run->model_identifier }}
                @endif
                <br>{{ \App\Support\Fecha::local($run->created_at)?->format('d/m/Y H:i') }}
            @endif
        </div>

        @if (! empty($asset->extracted_palette))
            <div class="hz-chips">
                @foreach (array_slice($asset->extracted_palette, 0, 6) as $c)
                    <span class="hz-chip"><span class="hz-swatch" style="background: {{ $c['hex'] }}"></span>{{ $c['hex'] }} · {{ $c['percent'] }}%</span>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Columna derecha: veredicto y detalle --}}
    <div class="hz-col">
        @if (! $run)
            <div class="hz-empty">Esta pieza todavia no tiene ninguna validacion.</div>
        @else
            <div class="hz-hero" style="background: {{ $fondoEstado }}">
                <div class="hz-ring" title="Calidad de ejecucion">
                    <svg width="104" height="104" viewBox="0 0 104 104">
                        <circle cx="52" cy="52" r="42" fill="none" stroke="rgba(128,128,128,.18)" stroke-width="10"></circle>
                        <circle cx="52" cy="52" r="42" fill="none" stroke="{{ $colorPuntaje }}" stroke-width="10" stroke-linecap="round"
                                stroke-dasharray="{{ $trazo }} {{ $circ }}"></circle>
                    </svg>
                    <div class="hz-ring-num">
                        <strong style="color: {{ $colorPuntaje }}">{{ $mostrarPuntaje ? number_format($puntaje, 0) : '—' }}</strong>
                        <span>Calidad</span>
                    </div>
                </div>

                <div class="hz-hero-body">
                    @if ($verdict)
                        <span class="hz-pill" style="background: {{ $colorEstado }}">{{ $verdict->status->label() }}</span>
                    @else
                        <span class="hz-pill" style="background:#6b7280">Sin veredicto</span>
                    @endif

                    @if ($run->status->value === 'failed')
                        <span class="hz-pill" style="background:#991b1b">Ejecucion fallida</span>
                    @endif

                    <div class="hz-titular hz-texto">{{ $titular }}</div>

                    @if ($mostrarPuntaje)
                        <div class="hz-explica">
                            Calidad de ejecucion sobre 100: cada regla incumplida resta una vez, segun su hallazgo mas grave.
                            Los bloqueantes no restan calidad: deciden si la pieza puede salir.
                        </div>
                    @endif
                </div>
            </div>

            <div class="hz-stats">
                <div class="hz-stat"><strong style="color:#dc2626">{{ $verdict?->blocking_count ?? 0 }}</strong><span>Bloqueantes</span></div>
                <div class="hz-stat"><strong style="color:#d97706">{{ $verdict?->major_count ?? 0 }}</strong><span>Mayores</span></div>
                <div class="hz-stat"><strong style="color:#3b82f6">{{ $verdict?->minor_count ?? 0 }}</strong><span>Menores</span></div>
                <div class="hz-stat"><strong style="color:#16a34a">{{ $resumen['cumple'] }}<span style="font-size:.75rem;font-weight:600"> / {{ $totalReglas }}</span></strong><span>Reglas cumplidas</span></div>
                <div class="hz-stat"><strong style="color:#2563eb">{{ $resumen['pendiente'] }}</strong><span>Sin verificar</span></div>
            </div>

            @if ($totalReglas > 0)
                <div class="hz-cob">
                    <div class="hz-cob-bar">
                        <i style="width: {{ $pct($resumen['cumple']) }}%; background:#16a34a"></i>
                        <i style="width: {{ $pct($resumen['incumple']) }}%; background:#dc2626"></i>
                        <i style="width: {{ $pct($resumen['pendiente']) }}%; background:#2563eb"></i>
                    </div>
                    <div class="hz-cob-ley">
                        <span><b style="background:#16a34a"></b>{{ $resumen['cumple'] }} cumplen</span>
                        <span><b style="background:#dc2626"></b>{{ $resumen['incumple'] }} incumplen</span>
                        <span><b style="background:#2563eb"></b>{{ $resumen['pendiente'] }} sin verificar</span>
                    </div>
                </div>
            @endif

            @if ($resumen['pendiente'] > 0)
                <div class="hz-alerta">
                    <strong>{{ $resumen['pendiente'] }} regla(s) no se pudieron verificar.</strong>
                    Mientras existan, la pieza no puede darse por aprobada. El motivo de cada una esta en la tabla de reglas.
                </div>
            @endif

            @if ($run->error_message)
                <div class="hz-error hz-texto">{{ $run->error_message }}</div>
            @endif

            <div class="hz-seccion">
                <h3>Hallazgos</h3>
                <span>{{ count($grupos) }} regla(s) con observaciones · {{ $findings->count() }} hallazgo(s)</span>
            </div>

            @forelse ($grupos as $g)
                @php $cg = $colores[$g['peor']->value] ?? '#6b7280'; @endphp

                <div class="hz-card" style="border-left-color: {{ $cg }}">
                    <div class="hz-head">
                        <span class="hz-tag" style="background: {{ $cg }}">{{ $g['peor']->label() }}</span>
                        @if ($g['codigo'])
                            <span class="hz-code">{{ $g['codigo'] }}</span>
                        @endif
                        <span class="hz-meta2">{{ $g['categoria'] }} · {{ $g['origen'] }}</span>
                        @if (count($g['items']) > 1)
                            <span class="hz-meta2">· {{ count($g['items']) }} observaciones</span>
                        @endif
                    </div>

                    @foreach ($g['items'] as $f)
                        @php
                            $ev = (array) ($f->evidence_data ?? []);
                            $degradadaDe = $ev['downgraded_from'] ?? null;
                            $confianza = $ev['confidence'] ?? null;
                            $dudoso = ($ev['doubtful'] ?? false) === true;
                            $nota = null;

                            if ($degradadaDe !== null) {
                                $nota = 'La regla la declara '.($etiquetasSeveridad[$degradadaDe] ?? $degradadaDe)
                                    .'; se registro como '.($etiquetasSeveridad[$f->severity->value] ?? $f->severity->value)
                                    .($confianza !== null ? ' por confianza '.number_format((float) $confianza, 2) : '');
                            } elseif ($confianza !== null) {
                                $nota = 'Confianza del modelo: '.number_format((float) $confianza, 2).($dudoso ? ' (requiere confirmacion humana)' : '');
                            }
                        @endphp

                        <div class="hz-sub">
                            <div class="hz-desc hz-texto">
                                @if (count($g['items']) > 1 && $f->severity !== $g['peor'])
                                    <span class="hz-tag" style="background: {{ $colores[$f->severity->value] ?? '#6b7280' }}; font-size:.5625rem; margin-right:.25rem">{{ $f->severity->label() }}</span>
                                @endif
                                {{ $f->description }}
                                @if ($dudoso)
                                    <span class="hz-dudoso">DUDOSO</span>
                                @endif
                            </div>

                            @if ($f->evidence)
                                <div class="hz-ev hz-texto">{{ $f->evidence }}</div>
                            @endif

                            @if ($f->suggestion)
                                <div class="hz-sug hz-texto">{{ $f->suggestion }}</div>
                            @endif

                            @if ($nota)
                                <div class="hz-nota">{{ $nota }}</div>
                            @endif

                            @if (! empty($ev['detected_hex']))
                                <div class="hz-chips">
                                    <span class="hz-chip"><span class="hz-swatch" style="background: {{ $ev['detected_hex'] }}"></span>detectado {{ $ev['detected_hex'] }}</span>
                                    @if (! empty($ev['nearest_hex']))
                                        <span class="hz-chip"><span class="hz-swatch" style="background: {{ $ev['nearest_hex'] }}"></span>autorizado {{ $ev['nearest_hex'] }}</span>
                                    @endif
                                    @if (isset($ev['delta_e']))
                                        <span class="hz-chip">ΔE {{ $ev['delta_e'] }}</span>
                                    @endif
                                    @if (isset($ev['tolerance']))
                                        <span class="hz-chip">tolerancia {{ $ev['tolerance'] }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @empty
                <div class="hz-empty">
                    <p><strong>Ningun hallazgo.</strong></p>
                    <p style="font-size:.875rem; margin-top:.25rem" class="hz-meta2">
                        No se registraron incumplimientos. Revisa abajo que reglas se verificaron y cuales quedaron pendientes.
                    </p>
                </div>
            @endforelse

            @if ($totalReglas > 0)
                <div class="hz-seccion">
                    <h3>Reglas aplicadas</h3>
                    <span>{{ $totalReglas }} reglas · {{ $resumen['incumple'] }} incumplen · {{ $resumen['cumple'] }} cumplen · {{ $resumen['pendiente'] }} sin verificar</span>
                </div>

                <table class="hz-reglas">
                    <colgroup><col class="c1"><col><col class="c3"></colgroup>
                    @foreach ($evaluadas as $e)
                        <tr>
                            <td class="hz-motor">{{ $e['code'] }}</td>
                            <td class="hz-texto">
                                {{ $e['title'] }}
                                <div class="hz-motor">{{ $e['origen'] }} · evalua {{ $e['motor'] }}</div>
                            </td>
                            <td class="hz-texto">
                                @if ($e['estado'] === 'cumple' && str_starts_with((string) $e['detalle'], 'No aplica'))
                                    <span class="hz-estado" style="background:#64748b">No aplica</span>
                                @else
                                    <span class="hz-estado" style="background: {{ $coloresEstado[$e['estado']] }}">{{ $etiquetasEstado[$e['estado']] }}</span>
                                @endif
                                @if ($e['severidad_hallada'] === 'blocking')
                                    <span class="hz-estado" style="background:#7f1d1d">BLOQUEANTE</span>
                                @endif
                                <div class="hz-motor" style="margin-top:.25rem">{{ $e['detalle'] }}</div>
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endif
    </div>
</div>
</div>
