@php
    use App\Enums\Severity;

    /**
     * Toda la logica va arriba y el HTML solo recorre arreglos ya armados.
     * Sin directivas mas alla de if, else y foreach: son las unicas que Blade
     * analiza sin ambiguedad, y esta pantalla tiene que compilar siempre.
     */
    $run = $this->run;

    $colores = [
        'blocking' => '#ef4444',
        'major' => '#f59e0b',
        'minor' => '#3b82f6',
        'info' => '#94a3b8',
    ];

    $coloresVeredicto = [
        'success' => '#16a34a',
        'warning' => '#d97706',
        'danger' => '#dc2626',
    ];

    // La vista previa del archivo aun no guardado. Se aisla porque el disco
    // temporal puede no admitir URLs y una excepcion aqui tumbaria la pagina
    // entera por una miniatura.
    $preview = null;

    if ($this->imagen !== null) {
        try {
            $preview = $this->imagen->temporaryUrl();
        } catch (\Throwable) {
            $preview = null;
        }
    }

    $hallazgos = [];
    $cumplidas = [];
    $sinEvaluar = [];
    $resumen = null;
    $paleta = [];

    $urlPieza = null;

    if ($run?->asset !== null) {
        try {
            $urlPieza = $run->asset->url();
        } catch (\Throwable) {
            $urlPieza = null;
        }
    }

    if ($run !== null) {
        $orden = ['blocking' => 0, 'major' => 1, 'minor' => 2, 'info' => 3];
        $meta = (array) ($run->deterministic_results ?? []);
        $iaReal = ($meta['ai_ran'] ?? false) === true && ($meta['ai_simulated'] ?? false) !== true;

        foreach ($run->findings->sortBy(fn ($f) => $orden[$f->severity->value] ?? 9) as $f) {
            $hallazgos[] = [
                'color' => $colores[$f->severity->value] ?? '#94a3b8',
                'severidad' => $f->severity->label(),
                'codigo' => $f->rule_code,
                'categoria' => $f->category->label(),
                'origen' => $f->origin->label(),
                'descripcion' => $f->description,
                'evidencia' => $f->evidence,
                'sugerencia' => $f->suggestion,
                'hex' => $f->evidence_data['detected_hex'] ?? null,
                'hex_ok' => $f->evidence_data['nearest_hex'] ?? null,
            ];
        }

        // Estado por regla desde la cobertura registrada: "cumple" solo si
        // se evaluo de verdad (ver RuleStatusReport).
        $reporteReglas = \App\Services\Validation\RuleStatusReport::for($run);
        $cumplidas = \App\Services\Validation\RuleStatusReport::codes($reporteReglas, 'cumple');
        $sinEvaluar = \App\Services\Validation\RuleStatusReport::codes($reporteReglas, 'pendiente');

        $v = $run->verdict;
        $puntaje = $v?->score !== null ? (float) $v->score : null;

        $resumen = [
            'etiqueta' => $v?->status->label() ?? 'Sin veredicto',
            'color' => $coloresVeredicto[$v?->status->color() ?? ''] ?? '#64748b',
            'puntaje' => $puntaje !== null ? number_format($puntaje, 1) : '—',
            'ancho' => $puntaje !== null ? max(2, min(100, $puntaje)) : 0,
            'modelo' => $run->model_identifier
                ? str_replace('claude-', '', (string) preg_replace('/-[0-9]{8}$/', '', $run->model_identifier))
                : null,
            'ia_real' => $iaReal,
            'costo' => $run->cost_usd !== null ? '$'.number_format((float) $run->cost_usd, 4) : null,
            'huella' => substr((string) $run->resolution_hash, 0, 10),
            'total' => count($run->resolved_rules_snapshot ?? []),
            'archivo' => $run->asset?->original_filename,
            'dimensiones' => $run->asset ? $run->asset->width.' x '.$run->asset->height.' px' : null,
            // URL propia y no la del otro recurso: esta pantalla no debe
            // depender de que el modulo de piezas este instalado.
            'url' => $urlPieza,
        ];

        foreach (array_slice((array) ($run->asset?->extracted_palette ?? []), 0, 6) as $c) {
            $paleta[] = ['hex' => $c['hex'] ?? '#000000', 'pct' => $c['percent'] ?? 0];
        }
    }
@endphp

<x-filament-panels::page>
    <style>
        .vr-grid { display: grid; gap: 1.5rem; }
        @media (min-width: 1100px) { .vr-grid { grid-template-columns: 380px 1fr; align-items: start; } }

        .vr-card { background: var(--gray-50, rgba(250,250,250,.6));
                   border: 1px solid rgba(128,128,128,.18); border-radius: 1rem; padding: 1.5rem; }
        .dark .vr-card { background: rgba(255,255,255,.02); }

        .vr-titulo { font-size: .8125rem; font-weight: 600; letter-spacing: .01em; margin-bottom: .5rem; }
        .vr-hint { font-size: .75rem; opacity: .55; margin-top: .375rem; line-height: 1.5; }
        .vr-field { margin-bottom: 1.5rem; }
        .vr-err { color: #dc2626; font-size: .75rem; margin-top: .375rem; }

        /* Zona de carga */
        .vr-file { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
                   overflow: hidden; clip: rect(0,0,0,0); border: 0; }
        .vr-drop { display: flex; flex-direction: column; align-items: center; justify-content: center;
                   gap: .625rem; min-height: 190px; padding: 1.25rem; cursor: pointer;
                   border: 2px dashed rgba(128,128,128,.3); border-radius: .875rem;
                   transition: border-color .15s, background .15s; text-align: center; }
        .vr-drop:hover { border-color: rgb(var(--primary-500, 245 158 11)); background: rgba(245,158,11,.04); }
        .vr-drop-icon { width: 2.25rem; height: 2.25rem; opacity: .35; }
        .vr-drop-t { font-size: .875rem; font-weight: 500; }
        .vr-drop-s { font-size: .75rem; opacity: .5; }
        .vr-thumb { max-width: 100%; max-height: 190px; border-radius: .625rem; display: block;
                    box-shadow: 0 1px 3px rgba(0,0,0,.12); }
        .vr-cambiar { font-size: .75rem; opacity: .55; }

        /* Resultado */
        .vr-hero { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
        .vr-hero-img { width: 96px; height: 96px; object-fit: cover; border-radius: .75rem;
                       border: 1px solid rgba(128,128,128,.2); flex: none; }
        .vr-hero-txt { flex: 1; min-width: 190px; }
        .vr-pill { display: inline-block; font-size: .75rem; font-weight: 600; color: #fff;
                   padding: .25rem .75rem; border-radius: 1rem; letter-spacing: .01em; }
        .vr-score-row { display: flex; align-items: baseline; gap: .375rem; margin-top: .625rem; }
        .vr-score { font-size: 2.25rem; font-weight: 700; line-height: 1; letter-spacing: -.02em; }
        .vr-score-max { font-size: .8125rem; opacity: .4; }
        .vr-bar { height: 6px; border-radius: 3px; background: rgba(128,128,128,.15);
                  overflow: hidden; margin-top: .75rem; }
        .vr-bar span { display: block; height: 100%; border-radius: 3px; transition: width .4s ease; }

        .vr-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(88px, 1fr));
                    gap: .625rem; margin-top: 1.25rem; }
        .vr-stat { border: 1px solid rgba(128,128,128,.15); border-radius: .625rem; padding: .625rem .75rem; }
        .vr-stat-n { font-size: 1.25rem; font-weight: 700; line-height: 1.1; }
        .vr-stat-l { font-size: .6875rem; opacity: .5; margin-top: .125rem; }

        .vr-meta { font-size: .6875rem; opacity: .45; line-height: 1.7;
                   font-family: ui-monospace, monospace; margin-top: 1rem; }

        .vr-sep { display: flex; align-items: center; gap: .625rem; margin: 1.75rem 0 .875rem;
                  font-size: .75rem; font-weight: 600; text-transform: uppercase;
                  letter-spacing: .06em; opacity: .5; }
        .vr-sep::after { content: ""; flex: 1; height: 1px; background: rgba(128,128,128,.18); }

        .vr-item { border: 1px solid rgba(128,128,128,.15); border-left-width: 3px;
                   border-radius: .625rem; padding: .875rem 1rem; margin-bottom: .75rem; }
        .vr-head { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-bottom: .5rem; }
        .vr-tag { font-size: .625rem; font-weight: 700; text-transform: uppercase; color: #fff;
                  padding: .1875rem .5rem; border-radius: .375rem; letter-spacing: .04em; }
        .vr-code { font-family: ui-monospace, monospace; font-size: .6875rem; opacity: .45; }
        .vr-desc { font-size: .875rem; line-height: 1.55; }
        .vr-ev { font-family: ui-monospace, monospace; font-size: .6875rem; opacity: .6;
                 margin-top: .5rem; padding: .375rem .5rem; border-radius: .375rem;
                 background: rgba(128,128,128,.07); }
        .vr-sug { font-size: .8125rem; opacity: .75; margin-top: .5rem; padding-left: .875rem;
                  border-left: 2px solid rgba(128,128,128,.25); }

        .vr-chips { display: flex; flex-wrap: wrap; gap: .375rem; }
        .vr-chip { font-family: ui-monospace, monospace; font-size: .6875rem; padding: .25rem .5rem;
                   border-radius: .375rem; background: rgba(128,128,128,.1); }
        .vr-chip-ok { color: #16a34a; background: rgba(22,163,74,.1); }
        .vr-chip-no { color: #b45309; background: rgba(180,83,9,.12); }
        .vr-sw { display: inline-block; width: .6875rem; height: .6875rem; border-radius: .25rem;
                 vertical-align: -1px; margin-right: .3125rem; border: 1px solid rgba(128,128,128,.35); }

        .vr-nota { display: flex; gap: .625rem; font-size: .75rem; line-height: 1.55;
                   padding: .75rem .875rem; border-radius: .625rem; background: rgba(245,158,11,.1); }
        .vr-nota-icon { width: 1rem; height: 1rem; flex: none; margin-top: .0625rem; opacity: .7; }

        .vr-vacio { display: flex; flex-direction: column; align-items: center; justify-content: center;
                    gap: .875rem; min-height: 320px; text-align: center; opacity: .35; }
        .vr-vacio svg { width: 3rem; height: 3rem; }
        .vr-vacio p { font-size: .875rem; max-width: 260px; line-height: 1.55; }

        .vr-cargando { display: flex; flex-direction: column; align-items: center; justify-content: center;
                       gap: .75rem; min-height: 320px; text-align: center; }
        .vr-spin { width: 2rem; height: 2rem; border: 2px solid rgba(128,128,128,.2);
                   border-top-color: rgb(var(--primary-500, 245 158 11)); border-radius: 50%;
                   animation: vr-rot .7s linear infinite; }
        @keyframes vr-rot { to { transform: rotate(360deg); } }
    </style>

    <div class="vr-grid">

        {{-- Panel izquierdo --}}
        <div class="vr-card">
            <div class="vr-field">
                <div class="vr-titulo">Marca</div>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model="marca">
                        <option value="">Elige una marca</option>
                        @foreach ($this->marcas as $ref => $nombre)
                            <option value="{{ $ref }}">{{ $nombre }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('marca')
                    <div class="vr-err">{{ $message }}</div>
                @enderror
                <div class="vr-hint">Unico dato obligatorio. De ella salen las reglas corporativas y de marca.</div>
            </div>

            <div class="vr-field">
                <div class="vr-titulo">Pieza</div>

                <input id="vr-archivo" class="vr-file" type="file" wire:model="imagen"
                       accept="image/jpeg,image/png,image/webp">

                <label for="vr-archivo" class="vr-drop">
                    <div wire:loading wire:target="imagen" class="vr-spin"></div>

                    <div wire:loading.remove wire:target="imagen" style="width:100%">
                        @if ($preview)
                            <img src="{{ $preview }}" alt="Vista previa" class="vr-thumb" style="margin:0 auto">
                            <div class="vr-cambiar" style="margin-top:.625rem">Clic para cambiar</div>
                        @else
                            <svg class="vr-drop-icon" style="margin:0 auto" fill="none" stroke="currentColor"
                                 stroke-width="1.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 7.5 7.5 12M12 7.5V21"/>
                            </svg>
                            <div class="vr-drop-t" style="margin-top:.625rem">Arrastra una imagen o haz clic</div>
                            <div class="vr-drop-s" style="margin-top:.25rem">JPG, PNG o WEBP, hasta 20 MB</div>
                        @endif
                    </div>
                </label>

                @error('imagen')
                    <div class="vr-err">{{ $message }}</div>
                @enderror
            </div>

            <div class="vr-field">
                <div class="vr-titulo">Canal de publicacion</div>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model="canal">
                        <option value="">Sin canal (no se valida formato)</option>
                        @foreach ($this->canales as $id => $etiqueta)
                            <option value="{{ $id }}">{{ $etiqueta }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            @if ($this->puedeElegirModelo)
                <div class="vr-field">
                    <div class="vr-titulo">Modelo de evaluacion</div>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="modelo">
                            @foreach ($this->modelos as $id => $etiqueta)
                                <option value="{{ $id }}">{{ $etiqueta }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            @endif

            <x-filament::button wire:click="validar" wire:loading.attr="disabled" size="lg" class="w-full">
                <span wire:loading.remove wire:target="validar">Validar pieza</span>
                <span wire:loading wire:target="validar">Evaluando...</span>
            </x-filament::button>

            @if ($runId)
                <div style="margin-top:.625rem">
                    <x-filament::button wire:click="limpiar" color="gray" outlined class="w-full">
                        Validar otra
                    </x-filament::button>
                </div>
            @endif

            <div class="vr-nota" style="margin-top:1.5rem">
                <svg class="vr-nota-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M11.25 11.25h1.5v5.25m-1.5 0h3m-3.75-9h.008v.008h-.008V7.5ZM21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                </svg>
                <div>
                    Sin canal de publicacion no se pueden verificar dimensiones, relacion de aspecto ni peso.
                    Si la marca tiene reglas de formato, quedaran como no verificadas y la pieza no podra salir aprobada.
                </div>
            </div>
        </div>

        {{-- Panel derecho --}}
        <div class="vr-card">
            <div wire:loading.flex wire:target="validar" class="vr-cargando">
                <div class="vr-spin"></div>
                <div style="font-size:.875rem; font-weight:500">Evaluando la pieza</div>
                <div style="font-size:.75rem; opacity:.5; max-width:280px; line-height:1.55">
                    El analisis por codigo es inmediato. El juicio del modelo sobre copy,
                    tono y cumplimiento toma entre diez y veinte segundos.
                </div>
            </div>

            <div wire:loading.remove wire:target="validar">
                @if ($run === null)
                    <div class="vr-vacio">
                        <svg fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 6.75h.008v.008H18V6.75Zm2.25 12.75H3.75a1.5 1.5 0 0 1-1.5-1.5V5.25a1.5 1.5 0 0 1 1.5-1.5h16.5a1.5 1.5 0 0 1 1.5 1.5v12.75a1.5 1.5 0 0 1-1.5 1.5Z"/>
                        </svg>
                        <p>Elige una marca, sube una imagen y presiona Validar. El resultado aparece aqui.</p>
                    </div>
                @else
                    <div class="vr-hero">
                        @if ($resumen['url'])
                            <img src="{{ $resumen['url'] }}" alt="" class="vr-hero-img">
                        @endif

                        <div class="vr-hero-txt">
                            <span class="vr-pill" style="background: {{ $resumen['color'] }}">
                                {{ $resumen['etiqueta'] }}
                            </span>

                            <div class="vr-score-row">
                                <span class="vr-score" style="color: {{ $resumen['color'] }}">{{ $resumen['puntaje'] }}</span>
                                <span class="vr-score-max">/ 100</span>
                            </div>

                            <div class="vr-bar">
                                <span style="width: {{ $resumen['ancho'] }}%; background: {{ $resumen['color'] }}"></span>
                            </div>
                        </div>
                    </div>

                    <div class="vr-stats">
                        <div class="vr-stat">
                            <div class="vr-stat-n">{{ $resumen['total'] }}</div>
                            <div class="vr-stat-l">reglas aplicadas</div>
                        </div>
                        <div class="vr-stat">
                            <div class="vr-stat-n" style="color:#dc2626">{{ count($hallazgos) }}</div>
                            <div class="vr-stat-l">hallazgos</div>
                        </div>
                        <div class="vr-stat">
                            <div class="vr-stat-n" style="color:#16a34a">{{ count($cumplidas) }}</div>
                            <div class="vr-stat-l">cumple</div>
                        </div>
                        @if (count($sinEvaluar) > 0)
                            <div class="vr-stat">
                                <div class="vr-stat-n" style="color:#b45309">{{ count($sinEvaluar) }}</div>
                                <div class="vr-stat-l">sin evaluar</div>
                            </div>
                        @endif
                    </div>

                    @if (count($sinEvaluar) > 0)
                        <div class="vr-nota" style="margin-top:1rem">
                            <svg class="vr-nota-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                            </svg>
                            <div>
                                <strong>{{ count($sinEvaluar) }} regla(s) no se pudieron verificar.</strong>
                                Por eso la pieza no puede darse por aprobada: revisa el detalle o pide revision humana.
                            </div>
                        </div>
                    @endif

                    @if (count($paleta) > 0)
                        <div class="vr-sep">Paleta detectada</div>
                        <div class="vr-chips">
                            @foreach ($paleta as $c)
                                <span class="vr-chip">
                                    <span class="vr-sw" style="background: {{ $c['hex'] }}"></span>{{ $c['hex'] }} {{ $c['pct'] }}%
                                </span>
                            @endforeach
                        </div>
                    @endif

                    <div class="vr-sep">Hallazgos</div>

                    @if (count($hallazgos) === 0)
                        <div style="font-size:.875rem; opacity:.55">
                            Ninguno entre las reglas que se pudieron verificar.
                        </div>
                    @endif

                    @foreach ($hallazgos as $h)
                        <div class="vr-item" style="border-left-color: {{ $h['color'] }}">
                            <div class="vr-head">
                                <span class="vr-tag" style="background: {{ $h['color'] }}">{{ $h['severidad'] }}</span>
                                <span class="vr-code">{{ $h['categoria'] }}</span>
                                @if ($h['codigo'])
                                    <span class="vr-code">· {{ $h['codigo'] }}</span>
                                @endif
                                <span class="vr-code">· {{ $h['origen'] }}</span>
                            </div>

                            <div class="vr-desc">{{ $h['descripcion'] }}</div>

                            @if ($h['evidencia'])
                                <div class="vr-ev">{{ $h['evidencia'] }}</div>
                            @endif

                            @if ($h['hex'])
                                <div class="vr-chips" style="margin-top:.5rem">
                                    <span class="vr-chip">
                                        <span class="vr-sw" style="background: {{ $h['hex'] }}"></span>detectado {{ $h['hex'] }}
                                    </span>
                                    @if ($h['hex_ok'])
                                        <span class="vr-chip">
                                            <span class="vr-sw" style="background: {{ $h['hex_ok'] }}"></span>autorizado {{ $h['hex_ok'] }}
                                        </span>
                                    @endif
                                </div>
                            @endif

                            @if ($h['sugerencia'])
                                <div class="vr-sug">{{ $h['sugerencia'] }}</div>
                            @endif
                        </div>
                    @endforeach

                    @if (count($cumplidas) > 0)
                        <div class="vr-sep">Cumple</div>
                        <div class="vr-chips">
                            @foreach ($cumplidas as $c)
                                <span class="vr-chip vr-chip-ok">{{ $c }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if (count($sinEvaluar) > 0)
                        <div class="vr-sep">Sin evaluar</div>
                        <div class="vr-chips">
                            @foreach ($sinEvaluar as $c)
                                <span class="vr-chip vr-chip-no">{{ $c }}</span>
                            @endforeach
                        </div>
                    @endif

                    <div class="vr-meta">
                        {{ $resumen['archivo'] }}
                        @if ($resumen['dimensiones'])
                            · {{ $resumen['dimensiones'] }}
                        @endif
                        <br>
                        huella {{ $resumen['huella'] }}...
                        · modelo {{ $resumen['modelo'] ?? 'sin IA' }}
                        @if (! $resumen['ia_real'])
                            · juicio no realizado
                        @endif
                        @if ($resumen['costo'])
                            · {{ $resumen['costo'] }}
                        @endif
                    </div>
                @endif
            </div>
        </div>

    </div>
</x-filament-panels::page>
