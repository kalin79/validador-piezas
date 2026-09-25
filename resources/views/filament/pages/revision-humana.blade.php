@php
    use App\Enums\FindingReviewState;
    use App\Enums\Severity;

    $run = $this->run;
    $cola = $this->cola;

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

    $pendientes = [];

    foreach ($cola as $r) {
        $pendientes[] = [
            'id' => $r->public_id,
            'archivo' => $r->asset?->original_filename ?? 'sin archivo',
            'marca' => $r->brand?->name ?? '',
            'veredicto' => $r->verdict?->status->label() ?? '',
            'color' => $coloresVeredicto[$r->verdict?->status->color() ?? ''] ?? '#64748b',
            'fecha' => \App\Support\Fecha::local($r->created_at)?->format('d/m H:i'),
            'activo' => $r->public_id === $this->runId,
        ];
    }

    $hallazgos = [];
    $cabecera = null;
    $urlPieza = null;

    if ($run !== null) {
        $orden = ['blocking' => 0, 'major' => 1, 'minor' => 2, 'info' => 3];

        foreach ($run->findings->sortBy(fn ($f) => $orden[$f->severity->value] ?? 9) as $f) {
            $decision = $this->decisiones[$f->id] ?? FindingReviewState::Confirmed->value;

            $hallazgos[] = [
                'id' => $f->id,
                'color' => $colores[$f->severity->value] ?? '#94a3b8',
                'severidad' => $f->severity->label(),
                'codigo' => $f->rule_code,
                'categoria' => $f->category->label(),
                'origen' => $f->origin->label(),
                'descripcion' => $f->description,
                'evidencia' => $f->evidence,
                'falso' => $decision === FindingReviewState::FalsePositive->value,
            ];
        }

        try {
            $urlPieza = $run->asset
                ? $run->asset->url()
                : null;
        } catch (\Throwable) {
            $urlPieza = null;
        }

        $v = $run->verdict;

        $cabecera = [
            'archivo' => $run->asset?->original_filename,
            'marca' => $run->brand?->fullName() ?? '',
            'veredicto' => $v?->status->label() ?? '',
            'color' => $coloresVeredicto[$v?->status->color() ?? ''] ?? '#64748b',
            'puntaje' => $v?->score !== null ? number_format((float) $v->score, 1) : '—',
            'modelo' => $run->model_identifier
                ? str_replace('claude-', '', (string) preg_replace('/-[0-9]{8}$/', '', $run->model_identifier))
                : 'sin IA',
            'cambio' => $this->veredictoFinal !== null && $this->veredictoFinal !== ($v?->status->value),
        ];
    }

    $falsos = 0;

    foreach ($hallazgos as $h) {
        if ($h['falso']) {
            $falsos++;
        }
    }
@endphp

<x-filament-panels::page>
    <style>
        .rv-grid { display: grid; gap: 1.5rem; }
        @media (min-width: 1100px) { .rv-grid { grid-template-columns: 300px 1fr; align-items: start; } }

        .rv-card { border: 1px solid rgba(128,128,128,.18); border-radius: 1rem; padding: 1.25rem; }
        .rv-tit { font-size: .75rem; font-weight: 600; text-transform: uppercase;
                  letter-spacing: .06em; opacity: .5; margin-bottom: .75rem; }

        .rv-cola { display: flex; flex-direction: column; gap: .375rem; max-height: 620px; overflow-y: auto; }
        .rv-fila { display: block; width: 100%; text-align: left; padding: .625rem .75rem;
                   border: 1px solid rgba(128,128,128,.15); border-radius: .625rem;
                   cursor: pointer; transition: background .12s, border-color .12s; }
        .rv-fila:hover { background: rgba(128,128,128,.06); }
        .rv-fila-on { border-color: rgb(var(--primary-500, 245 158 11)); background: rgba(245,158,11,.07); }
        .rv-fila-t { font-size: .75rem; font-weight: 500; overflow: hidden;
                     text-overflow: ellipsis; white-space: nowrap; }
        .rv-fila-s { font-size: .6875rem; opacity: .5; margin-top: .1875rem; }
        .rv-dot { display: inline-block; width: .5rem; height: .5rem; border-radius: 50%;
                  margin-right: .375rem; vertical-align: 1px; }

        .rv-hero { display: flex; gap: 1.25rem; flex-wrap: wrap; align-items: center;
                   padding-bottom: 1.25rem; border-bottom: 1px solid rgba(128,128,128,.15); }
        .rv-img { width: 88px; height: 88px; object-fit: cover; border-radius: .75rem;
                  border: 1px solid rgba(128,128,128,.2); flex: none; }
        .rv-pill { display: inline-block; font-size: .6875rem; font-weight: 600; color: #fff;
                   padding: .1875rem .625rem; border-radius: 1rem; }
        .rv-meta { font-size: .6875rem; opacity: .5; font-family: ui-monospace, monospace; margin-top: .375rem; }

        .rv-item { border: 1px solid rgba(128,128,128,.15); border-left-width: 3px;
                   border-radius: .625rem; padding: .875rem 1rem; margin-bottom: .75rem;
                   transition: opacity .15s; }
        .rv-item-falso { opacity: .45; }
        .rv-head { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-bottom: .5rem; }
        .rv-tag { font-size: .625rem; font-weight: 700; text-transform: uppercase; color: #fff;
                  padding: .1875rem .5rem; border-radius: .375rem; letter-spacing: .04em; }
        .rv-code { font-family: ui-monospace, monospace; font-size: .6875rem; opacity: .45; }
        .rv-desc { font-size: .875rem; line-height: 1.55; }
        .rv-ev { font-family: ui-monospace, monospace; font-size: .6875rem; opacity: .55; margin-top: .375rem; }
        .rv-juicio { display: flex; gap: .5rem; margin-top: .75rem; }
        .rv-btn { font-size: .75rem; font-weight: 500; padding: .3125rem .75rem; border-radius: .5rem;
                  border: 1px solid rgba(128,128,128,.25); cursor: pointer; background: transparent; }
        .rv-btn-on-ok { background: rgba(22,163,74,.14); border-color: rgba(22,163,74,.4); color: #16a34a; }
        .rv-btn-on-no { background: rgba(220,38,38,.14); border-color: rgba(220,38,38,.4); color: #dc2626; }

        .rv-nuevo { border: 1px dashed rgba(128,128,128,.3); border-radius: .75rem;
                    padding: 1rem; margin-top: .75rem; }
        .rv-row { display: grid; gap: .625rem; margin-bottom: .625rem; }
        @media (min-width: 720px) { .rv-row { grid-template-columns: 1fr 1fr 1fr; } }
        .rv-lbl { font-size: .6875rem; font-weight: 600; opacity: .6; margin-bottom: .25rem; display: block; }

        .rv-agregado { display: flex; justify-content: space-between; gap: .75rem; align-items: flex-start;
                       font-size: .8125rem; padding: .625rem .75rem; border-radius: .5rem;
                       background: rgba(59,130,246,.08); margin-bottom: .375rem; }

        .rv-final { border-top: 1px solid rgba(128,128,128,.15); margin-top: 1.5rem; padding-top: 1.25rem; }
        .rv-aviso { font-size: .75rem; padding: .625rem .75rem; border-radius: .5rem;
                    background: rgba(245,158,11,.12); margin: .75rem 0; line-height: 1.5; }
        .rv-vacio { display: flex; flex-direction: column; align-items: center; justify-content: center;
                    gap: .75rem; min-height: 340px; text-align: center; opacity: .4; }
        .rv-vacio svg { width: 2.75rem; height: 2.75rem; }
        .rv-vacio p { font-size: .875rem; max-width: 300px; line-height: 1.55; }
    </style>

    <div class="rv-grid">

        <div class="rv-card">
            <div class="rv-tit">Pendientes ({{ count($pendientes) }})</div>

            @if (count($pendientes) === 0)
                <div style="font-size:.8125rem; opacity:.5; line-height:1.55">
                    No hay validaciones sin revisar. Valida una pieza y vuelve aqui.
                </div>
            @endif

            <div class="rv-cola">
                @foreach ($pendientes as $p)
                    <button type="button" wire:click="abrir('{{ $p['id'] }}')"
                            class="rv-fila {{ $p['activo'] ? 'rv-fila-on' : '' }}">
                        <div class="rv-fila-t">
                            <span class="rv-dot" style="background: {{ $p['color'] }}"></span>{{ $p['archivo'] }}
                        </div>
                        <div class="rv-fila-s">{{ $p['marca'] }} · {{ $p['veredicto'] }} · {{ $p['fecha'] }}</div>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="rv-card">
            @if ($run === null)
                <div class="rv-vacio">
                    <svg fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z"/>
                    </svg>
                    <p>Elige una validacion de la lista para revisarla. Tu juicio alimenta las metricas de calidad del motor.</p>
                </div>
            @else
                <div class="rv-hero">
                    @if ($urlPieza)
                        <img src="{{ $urlPieza }}" alt="" class="rv-img">
                    @endif

                    <div style="flex:1; min-width:180px">
                        <div style="font-size:.875rem; font-weight:600">{{ $cabecera['archivo'] }}</div>
                        <div style="margin-top:.5rem">
                            <span class="rv-pill" style="background: {{ $cabecera['color'] }}">
                                {{ $cabecera['veredicto'] }} · {{ $cabecera['puntaje'] }}
                            </span>
                        </div>
                        <div class="rv-meta">{{ $cabecera['marca'] }} · modelo {{ $cabecera['modelo'] }}</div>
                    </div>
                </div>

                <div class="rv-tit" style="margin-top:1.5rem">
                    Hallazgos de la maquina ({{ count($hallazgos) }})
                </div>

                @if (count($hallazgos) === 0)
                    <div style="font-size:.8125rem; opacity:.55">
                        La maquina no reporto ningun hallazgo. Si crees que debio hacerlo, agregalo abajo.
                    </div>
                @endif

                @foreach ($hallazgos as $h)
                    <div class="rv-item {{ $h['falso'] ? 'rv-item-falso' : '' }}"
                         style="border-left-color: {{ $h['color'] }}">
                        <div class="rv-head">
                            <span class="rv-tag" style="background: {{ $h['color'] }}">{{ $h['severidad'] }}</span>
                            <span class="rv-code">{{ $h['categoria'] }}</span>
                            @if ($h['codigo'])
                                <span class="rv-code">· {{ $h['codigo'] }}</span>
                            @endif
                            <span class="rv-code">· {{ $h['origen'] }}</span>
                        </div>

                        <div class="rv-desc">{{ $h['descripcion'] }}</div>

                        @if ($h['evidencia'])
                            <div class="rv-ev">{{ $h['evidencia'] }}</div>
                        @endif

                        <div class="rv-juicio">
                            <button type="button" wire:click="alternar({{ $h['id'] }})"
                                    class="rv-btn {{ $h['falso'] ? '' : 'rv-btn-on-ok' }}">
                                Correcto
                            </button>
                            <button type="button" wire:click="alternar({{ $h['id'] }})"
                                    class="rv-btn {{ $h['falso'] ? 'rv-btn-on-no' : '' }}">
                                Falso positivo
                            </button>
                        </div>
                    </div>
                @endforeach

                <div class="rv-tit" style="margin-top:1.5rem">Lo que la maquina no vio</div>

                @foreach ($this->agregados as $i => $a)
                    <div class="rv-agregado">
                        <div>
                            <strong>{{ $this->severidades[$a['severity']] ?? $a['severity'] }}</strong>
                            @if ($a['rule_code'])
                                <span class="rv-code">· {{ $a['rule_code'] }}</span>
                            @endif
                            <div style="margin-top:.1875rem">{{ $a['description'] }}</div>
                        </div>
                        <button type="button" wire:click="quitarHallazgo({{ $i }})" class="rv-btn">Quitar</button>
                    </div>
                @endforeach

                <div class="rv-nuevo">
                    <div class="rv-row">
                        <div>
                            <label class="rv-lbl">Severidad</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="nuevaSeveridad">
                                    @foreach ($this->severidades as $val => $lbl)
                                        <option value="{{ $val }}">{{ $lbl }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="rv-lbl">Categoria</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="nuevaCategoria">
                                    @foreach ($this->categorias as $val => $lbl)
                                        <option value="{{ $val }}">{{ $lbl }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="rv-lbl">Codigo de regla</label>
                            <x-filament::input.wrapper>
                                <x-filament::input wire:model="nuevoCodigo" placeholder="COMP-014" />
                            </x-filament::input.wrapper>
                        </div>
                    </div>

                    <label class="rv-lbl">Que incumple</label>
                    <x-filament::input.wrapper>
                        <textarea wire:model="nuevaDescripcion" rows="2"
                                  class="fi-input block w-full border-none bg-transparent px-3 py-1.5 text-base sm:text-sm"
                                  placeholder="Describe el incumplimiento que el sistema no detecto"></textarea>
                    </x-filament::input.wrapper>

                    <div style="margin-top:.625rem">
                        <x-filament::button wire:click="agregarHallazgo" color="gray" size="sm">
                            Agregar hallazgo
                        </x-filament::button>
                    </div>
                </div>

                <div class="rv-final">
                    <div class="rv-tit">Veredicto final</div>

                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="veredictoFinal">
                            <option value="">Elige un veredicto</option>
                            <option value="approved">Aprobado</option>
                            <option value="approved_with_observations">Aprobado con observaciones</option>
                            <option value="rejected">Rechazado</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>

                    @if ($cabecera['cambio'])
                        <div class="rv-aviso">
                            Estas cambiando el veredicto de la maquina. Explica por que: sin el motivo,
                            la anulacion no sirve para corregir la regla despues.
                        </div>
                    @endif

                    <div style="margin-top:.75rem">
                        <label class="rv-lbl">Justificacion</label>
                        <x-filament::input.wrapper>
                            <textarea wire:model="justificacion" rows="2"
                                      class="fi-input block w-full border-none bg-transparent px-3 py-1.5 text-base sm:text-sm"
                                      placeholder="Opcional, salvo que cambies el veredicto"></textarea>
                        </x-filament::input.wrapper>
                    </div>

                    @if ($falsos > 0)
                        <div class="rv-aviso">
                            Marcaste <strong>{{ $falsos }}</strong> hallazgo(s) como falso positivo.
                            Van a aparecer en las metricas por regla, que es donde se ve cual esta mal escrita.
                        </div>
                    @endif

                    <div style="display:flex; gap:.625rem; margin-top:1rem">
                        <x-filament::button wire:click="guardar" wire:loading.attr="disabled">
                            Registrar revision
                        </x-filament::button>
                        <x-filament::button wire:click="reiniciar" color="gray" outlined>
                            Cancelar
                        </x-filament::button>
                    </div>
                </div>
            @endif
        </div>

    </div>
</x-filament-panels::page>
