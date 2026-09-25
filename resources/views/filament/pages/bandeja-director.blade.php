@php
    use App\Enums\DirectorReviewStatus;
    use App\Support\Fecha;

    $servicio = app(\App\Services\Director\EnvioAlDirector::class);
    $envios = $this->envios;
    $hex = ['success' => '#16a34a', 'warning' => '#d97706', 'danger' => '#dc2626', 'info' => '#2563eb', 'gray' => '#64748b'];
    $puntaje = fn (?float $s): string => $s === null ? '' : ' · '.rtrim(rtrim(number_format($s, 1, '.', ''), '0'), '.');
@endphp

<x-filament-panels::page>
<style>
    .bd { --b: #e5e7eb; --t2: #6b7280; --fondo: #fff; }
    .dark .bd { --b: rgba(255,255,255,.10); --t2: #9ca3af; --fondo: rgba(255,255,255,.03); }
    .bd-tabs { display: flex; gap: .375rem; margin-bottom: 1rem; }
    .bd-tab { padding: .45rem .9rem; border-radius: 999px; font-size: .8125rem; font-weight: 600; border: 1px solid var(--b); color: var(--t2); background: var(--fondo); cursor: pointer; }
    .bd-tab.on { background: #111827; color: #fff; border-color: #111827; }
    .dark .bd-tab.on { background: #fff; color: #111827; border-color: #fff; }
    .bd-card { display: grid; grid-template-columns: 180px 1fr; gap: 1.25rem; padding: 1.125rem; border: 1px solid var(--b); border-radius: 1rem; background: var(--fondo); margin-bottom: 1rem; }
    @media (max-width: 720px) { .bd-card { grid-template-columns: 1fr; } }
    .bd-img { width: 100%; aspect-ratio: 1; object-fit: contain; border-radius: .75rem; border: 1px solid var(--b); background: repeating-conic-gradient(#f3f4f6 0 25%, #fff 0 50%) 0 0 / 16px 16px; }
    .bd-arch { font-weight: 700; font-size: 1rem; word-break: break-all; }
    .bd-sub { font-size: .8125rem; color: var(--t2); margin-top: .125rem; }
    .bd-chips { display: flex; flex-wrap: wrap; gap: .375rem; margin: .625rem 0; }
    .bd-chip { display: inline-flex; align-items: center; gap: .3rem; font-size: .75rem; font-weight: 600; padding: .2rem .6rem; border-radius: 999px; border: 1px solid; }
    .bd-nota { font-size: .8125rem; padding: .625rem .75rem; border-radius: .625rem; background: rgba(120,120,120,.06); margin: .5rem 0; }
    .bd-nota b { display: block; font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em; color: var(--t2); margin-bottom: .125rem; }
    .bd-alerta { font-size: .8125rem; padding: .625rem .75rem; border-radius: .625rem; border: 1px solid #fcd34d; background: #fffbeb; color: #92400e; margin: .5rem 0; }
    .dark .bd-alerta { background: rgba(251,191,36,.08); color: #fcd34d; border-color: rgba(251,191,36,.3); }
    .bd-comentario { width: 100%; min-height: 4.5rem; border: 1px solid var(--b); border-radius: .625rem; padding: .5rem .625rem; font-size: .875rem; background: transparent; color: inherit; margin-top: .5rem; }
    .bd-acciones { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .625rem; align-items: center; }
    .bd-btn { font-size: .8125rem; font-weight: 600; padding: .5rem 1rem; border-radius: .625rem; border: 1px solid transparent; cursor: pointer; }
    .bd-ok { background: #16a34a; color: #fff; }
    .bd-no { background: #fff; color: #dc2626; border-color: #fca5a5; }
    .dark .bd-no { background: transparent; }
    .bd-link { font-size: .8125rem; font-weight: 600; color: #2563eb; background: none; border: 0; cursor: pointer; padding: 0; }
    .bd-hallazgos { grid-column: 1 / -1; border-top: 1px solid var(--b); padding-top: 1rem; }
    .bd-vacio { text-align: center; padding: 3rem 1rem; border: 1px dashed var(--b); border-radius: 1rem; color: var(--t2); }
    .bd-hist td, .bd-hist th { padding: .5rem .5rem; border-bottom: 1px solid var(--b); font-size: .8125rem; text-align: left; vertical-align: top; }
    .bd-hist th { font-size: .6875rem; text-transform: uppercase; letter-spacing: .03em; color: var(--t2); }
    .bd-hist { width: 100%; border-collapse: collapse; background: var(--fondo); border: 1px solid var(--b); border-radius: 1rem; overflow: hidden; }
</style>

<div class="bd">
    <div class="bd-tabs">
        <button type="button" wire:click="ver('pendientes')" class="bd-tab {{ $this->vista === 'pendientes' ? 'on' : '' }}">Pendientes ({{ $this->pendientes }})</button>
        <button type="button" wire:click="ver('historial')" class="bd-tab {{ $this->vista === 'historial' ? 'on' : '' }}">Historial</button>
    </div>

    @if ($this->vista === 'pendientes')
        @forelse ($envios as $e)
            @php
                $asset = $e->asset;
                $posterior = $servicio->validacionPosterior($e);
                [$vPost] = $posterior ? $servicio::veredictoEfectivo($posterior) : [null];
                $previas = $this->devolucionesPrevias($e);
                $motivoAprobar = $servicio->motivoParaNoDecidir($e, auth()->user(), true);
                $motivoDevolver = $servicio->motivoParaNoDecidir($e, auth()->user(), false);
                $color = $hex[$e->verdict_at_send->color()] ?? '#64748b';
            @endphp
            <div class="bd-card" wire:key="env-{{ $e->public_id }}">
                <a href="{{ $asset?->url() }}" target="_blank" rel="noopener">
                    <img class="bd-img" src="{{ $asset?->url() }}" alt="{{ $asset?->original_filename }}" loading="lazy">
                </a>

                <div>
                    <div class="bd-arch">{{ $asset?->original_filename ?? 'Pieza sin archivo' }}</div>
                    <div class="bd-sub">
                        {{ $e->brand?->client?->name }} · {{ $e->brand?->name }}
                        @if ($asset?->submission?->campaign) · {{ $asset->submission->campaign }} @endif
                        @if ($asset?->submission?->channel) · {{ $asset->submission->channel }} @endif
                    </div>
                    <div class="bd-sub">Enviada por <b>{{ $e->sender?->name }}</b> el {{ Fecha::local($e->created_at)?->format('d/m/Y H:i') }}</div>

                    <div class="bd-chips">
                        <span class="bd-chip" style="color: {{ $color }}; border-color: {{ $color }}55; background: {{ $color }}10">
                            {{ $e->verdict_at_send->label() }}{{ $puntaje($e->score_at_send) }}
                        </span>
                        @if ($e->verdict_from_human)
                            <span class="bd-chip" style="color:#7c3aed; border-color:#7c3aed55; background:#7c3aed10">Veredicto de revision humana</span>
                        @endif
                        @if ($e->validationRun?->model_identifier)
                            <span class="bd-chip" style="color:#64748b; border-color:#64748b44">{{ str_replace('claude-', '', (string) preg_replace('/-[0-9]{8}$/', '', $e->validationRun->model_identifier)) }}</span>
                        @endif
                        @if ($asset?->width)
                            <span class="bd-chip" style="color:#64748b; border-color:#64748b44">{{ $asset->width }}×{{ $asset->height }} px</span>
                        @endif
                    </div>

                    @if ($e->sender_note)
                        <div class="bd-nota"><b>Nota de quien envia</b>{{ $e->sender_note }}</div>
                    @endif

                    @foreach ($previas as $p)
                        <div class="bd-nota"><b>Devuelta antes · {{ Fecha::local($p->decided_at)?->format('d/m H:i') }} · {{ $p->decider?->name }}</b>{{ $p->decision_comment }}</div>
                    @endforeach

                    @if ($posterior && $vPost !== $e->verdict_at_send)
                        <div class="bd-alerta">
                            Se revalido despues del envio y el veredicto ahora es <b>{{ $vPost?->label() ?? 'sin veredicto' }}</b>{{ $puntaje($posterior->verdict?->score) }}.
                            @if ($motivoAprobar) Solo se puede devolver. @endif
                        </div>
                    @endif

                    <button type="button" class="bd-link" wire:click="alternar('{{ $e->public_id }}')">
                        {{ $this->abierto === $e->public_id ? 'Ocultar hallazgos' : 'Ver hallazgos de la validacion' }}
                    </button>

                    @if ($motivoDevolver)
                        <div class="bd-alerta">{{ $motivoDevolver }}</div>
                    @else
                        <textarea class="bd-comentario" wire:model="comentarios.{{ $e->public_id }}"
                            placeholder="Comentario para quien envio. Obligatorio si la devuelves: indica que corregir."></textarea>
                        <div class="bd-acciones">
                            <button type="button" class="bd-btn bd-ok" wire:click="aprobar('{{ $e->public_id }}')" wire:loading.attr="disabled"
                                @if ($motivoAprobar) disabled title="{{ $motivoAprobar }}" style="opacity:.45; cursor:not-allowed" @endif>
                                Aprobar
                            </button>
                            <button type="button" class="bd-btn bd-no" wire:click="devolver('{{ $e->public_id }}')" wire:loading.attr="disabled">
                                Devolver con comentario
                            </button>
                        </div>
                    @endif
                </div>

                @if ($this->abierto === $e->public_id)
                    <div class="bd-hallazgos">
                        @include('filament.modals.hallazgos', [
                            'run' => $e->validationRun()->with(['findings', 'verdict'])->first(),
                            'asset' => $asset,
                            'url' => $asset?->url(),
                        ])
                    </div>
                @endif
            </div>
        @empty
            <div class="bd-vacio">No hay piezas esperando tu decision.</div>
        @endforelse
    @else
        @if ($envios->isEmpty())
            <div class="bd-vacio">Todavia no hay decisiones registradas.</div>
        @else
            <table class="bd-hist">
                <thead><tr><th>Pieza</th><th>Marca</th><th>Enviada por</th><th>Resultado</th><th>Decidio</th><th>Comentario</th></tr></thead>
                <tbody>
                    @foreach ($envios as $e)
                        @php $c = $hex[$e->status->color()] ?? '#64748b'; @endphp
                        <tr wire:key="h-{{ $e->public_id }}">
                            <td>{{ $e->asset?->original_filename }}</td>
                            <td>{{ $e->brand?->name }}</td>
                            <td>{{ $e->sender?->name }}<br><span style="color:var(--t2)">{{ Fecha::local($e->created_at)?->format('d/m/Y H:i') }}</span></td>
                            <td><span class="bd-chip" style="color: {{ $c }}; border-color: {{ $c }}55">{{ $e->status->label() }}</span></td>
                            <td>{{ $e->decider?->name }}<br><span style="color:var(--t2)">{{ Fecha::local($e->decided_at)?->format('d/m/Y H:i') }}</span></td>
                            <td>{{ $e->decision_comment ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
</div>
</x-filament-panels::page>
