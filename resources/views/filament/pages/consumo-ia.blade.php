@php
    $r = $this->reporte;
    $usd = fn (?float $v, int $d = 2, string $vacio = 'sin tarifa'): string => $v === null ? $vacio : 'US$ '.number_format($v, $d, '.', ',');
    $n = fn (int|float $v): string => number_format((float) $v, 0, '.', ',');
    $maxCosto = $r ? max(0.0001, (float) collect($r['clientes'])->max('costo')) : 1;
@endphp

<x-filament-panels::page>
<style>
    .ci { --b: rgba(120,120,120,.18); --s: rgba(120,120,120,.06); --t2: rgba(100,100,110,1); }
    .dark .ci { --b: rgba(255,255,255,.10); --s: rgba(255,255,255,.04); --t2: rgba(170,170,180,1); }
    .ci-filtros { display: flex; flex-wrap: wrap; gap: .75rem; align-items: end; padding: 1rem; border: 1px solid var(--b); border-radius: 1rem; }
    .ci-campo label { display: block; font-size: .75rem; font-weight: 600; color: var(--t2); margin-bottom: .25rem; }
    .ci-campo input, .ci-campo select { border: 1px solid var(--b); border-radius: .5rem; padding: .45rem .6rem; font-size: .875rem; background: transparent; color: inherit; }
    .ci-chips { display: flex; flex-wrap: wrap; gap: .375rem; }
    .ci-chip { font-size: .75rem; padding: .35rem .7rem; border-radius: 999px; border: 1px solid var(--b); background: var(--s); cursor: pointer; }
    .ci-chip:hover { border-color: #f59e0b; }
    .ci-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; margin-top: 1rem; }
    @media (max-width: 768px) { .ci-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .ci-kpi { border: 1px solid var(--b); border-radius: 1rem; padding: .875rem 1rem; }
    .ci-kpi span { font-size: .75rem; color: var(--t2); }
    .ci-kpi strong { display: block; font-size: 1.5rem; font-weight: 800; margin-top: .125rem; }
    .ci-kpi small { font-size: .6875rem; color: var(--t2); }
    .ci-card { border: 1px solid var(--b); border-radius: 1rem; margin-top: 1rem; overflow: hidden; }
    .ci-card summary { list-style: none; cursor: pointer; padding: 1rem 1.125rem; display: grid; grid-template-columns: minmax(0, 1.6fr) repeat(4, minmax(0, 1fr)); gap: .75rem; align-items: center; }
    .ci-card summary::-webkit-details-marker { display: none; }
    @media (max-width: 768px) { .ci-card summary { grid-template-columns: 1fr 1fr; } }
    .ci-nombre { font-weight: 700; }
    .ci-barra { height: .375rem; border-radius: 999px; background: var(--s); margin-top: .375rem; overflow: hidden; }
    .ci-barra i { display: block; height: 100%; background: #f59e0b; border-radius: 999px; }
    .ci-dato { text-align: right; }
    .ci-dato b { display: block; font-size: .9375rem; }
    .ci-dato span { font-size: .6875rem; color: var(--t2); }
    .ci-det { padding: 0 1.125rem 1rem; display: grid; grid-template-columns: 1fr; gap: 1.25rem; }
    .ci-det h4 { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--t2); margin-bottom: .375rem; }
    .ci-tabla { width: 100%; border-collapse: collapse; font-size: .8125rem; }
    .ci-tabla td { padding: .375rem .25rem; border-bottom: 1px solid var(--b); }
    .ci-tabla td.r, .ci-tabla th.r { text-align: right; font-variant-numeric: tabular-nums; }
    .ci-tabla th { padding: .25rem .25rem .375rem; border-bottom: 1px solid var(--b); font-size: .6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--t2); text-align: left; white-space: nowrap; }
    .ci-nota { font-size: .75rem; color: var(--t2); margin-top: 1rem; line-height: 1.6; padding: .75rem 1rem; border-radius: .75rem; background: var(--s); }
    .ci-aviso { font-size: .8125rem; margin-top: 1rem; padding: .625rem .875rem; border-radius: .75rem; background: rgba(217,119,6,.10); border: 1px solid rgba(217,119,6,.3); }
    .ci-vacio { text-align: center; padding: 2.5rem 1rem; border: 1px dashed var(--b); border-radius: 1rem; margin-top: 1rem; color: var(--t2); }
</style>

<div class="ci">
    <div class="ci-filtros">
        <div class="ci-campo"><label>Desde</label><input type="date" wire:model.live="desde"></div>
        <div class="ci-campo"><label>Hasta</label><input type="date" wire:model.live="hasta"></div>
        <div class="ci-campo">
            <label>Cliente</label>
            <select wire:model.live="cliente">
                <option value="">Todos</option>
                @foreach ($this->clientes as $id => $nombre)
                    <option value="{{ $id }}">{{ $nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="ci-chips">
            <button type="button" class="ci-chip" wire:click="periodo('mes')">Este mes</button>
            <button type="button" class="ci-chip" wire:click="periodo('mes_anterior')">Mes anterior</button>
            <button type="button" class="ci-chip" wire:click="periodo('30_dias')">Últimos 30 días</button>
            <button type="button" class="ci-chip" wire:click="periodo('anio')">Este año</button>
        </div>
        <div style="margin-left:auto">
            <x-filament::button wire:click="exportar" icon="heroicon-o-arrow-down-tray" color="gray" :disabled="$r === null || $r['clientes'] === []">
                Exportar CSV
            </x-filament::button>
        </div>
    </div>

    @if ($r === null)
        <div class="ci-aviso">El rango de fechas no es valido: "hasta" debe ser posterior a "desde" y el periodo no puede superar 400 dias.</div>
    @else
        <div class="ci-kpis">
            <div class="ci-kpi"><span>Costo del periodo</span><strong style="color:#d97706">{{ $usd($r['total']['costo']) }}</strong><small>tarifa vigente{{ $r['total']['costo_incompleto'] ? ' · incompleto' : '' }}</small></div>
            <div class="ci-kpi"><span>Validaciones con IA</span><strong>{{ $n($r['total']['llamadas']) }}</strong><small>llamadas a la API</small></div>
            <div class="ci-kpi"><span>Tokens</span><strong>{{ $n($r['total']['entrada'] + $r['total']['salida']) }}</strong><small>{{ $n($r['total']['entrada']) }} entrada · {{ $n($r['total']['salida']) }} salida</small></div>
            <div class="ci-kpi"><span>Costo promedio</span><strong>{{ $usd($r['total']['promedio'], 4, '—') }}</strong><small>por validacion</small></div>
        </div>

        @if ($r['modelos_sin_tarifa'] !== [])
            <div class="ci-aviso">Sin tarifa configurada para: <strong>{{ implode(', ', $r['modelos_sin_tarifa']) }}</strong>. Sus tokens se cuentan, pero su costo no se suma. Agrega la tarifa en config/ai.php.</div>
        @endif

        @forelse ($r['clientes'] as $c)
            <details class="ci-card" @if (count($r['clientes']) === 1) open @endif>
                <summary>
                    <div>
                        <div class="ci-nombre">{{ $c['cliente'] }}</div>
                        <div class="ci-barra"><i style="width: {{ round(($c['costo'] ?? 0) / $maxCosto * 100, 1) }}%"></i></div>
                    </div>
                    <div class="ci-dato"><b>{{ $usd($c['costo']) }}</b><span>costo</span></div>
                    <div class="ci-dato"><b>{{ $n($c['llamadas']) }}</b><span>validaciones</span></div>
                    <div class="ci-dato"><b>{{ $n($c['entrada'] + $c['salida']) }}</b><span>tokens</span></div>
                    <div class="ci-dato"><b>{{ $usd($c['promedio'], 4, '—') }}</b><span>promedio</span></div>
                </summary>

                <div class="ci-det">
                    <div>
                        <h4>Por marca</h4>
                        <table class="ci-tabla">
                            <thead><tr><th>Marca</th><th class="r">Validaciones</th><th class="r">Tokens entrada</th><th class="r">Tokens salida</th><th class="r">Costo</th></tr></thead>
                            @foreach ($c['marcas'] as $m)
                                <tr><td>{{ $m['nombre'] }}</td><td class="r">{{ $n($m['llamadas']) }}</td><td class="r">{{ $n($m['entrada']) }}</td><td class="r">{{ $n($m['salida']) }}</td><td class="r"><b>{{ $usd($m['costo']) }}</b></td></tr>
                            @endforeach
                        </table>
                    </div>
                    <div>
                        <h4>Por modelo</h4>
                        <table class="ci-tabla">
                            <thead><tr><th>Modelo</th><th class="r">Validaciones</th><th class="r">Tokens entrada</th><th class="r">Tokens salida</th><th class="r">Costo</th></tr></thead>
                            @foreach ($c['modelos'] as $m)
                                <tr><td>{{ $m['nombre'] }}</td><td class="r">{{ $n($m['llamadas']) }}</td><td class="r">{{ $n($m['entrada']) }}</td><td class="r">{{ $n($m['salida']) }}</td><td class="r"><b>{{ $usd($m['costo']) }}</b></td></tr>
                            @endforeach
                        </table>
                    </div>
                </div>
            </details>
        @empty
            <div class="ci-vacio">No hay validaciones con IA en este periodo.</div>
        @endforelse

        <div class="ci-nota">
            <strong>Como se calcula.</strong>
            Tokens: los que reporto la API de Anthropic en cada validacion (incluyen la imagen); son exactos.
            Costo: tokens × tarifa publica de Anthropic verificada el {{ \Carbon\Carbon::parse(config('ai.pricing_verified_at'))->format('d/m/Y') }}, en dolares y sin impuestos.
            Es el monto exacto si tu cuenta no tiene descuentos; la factura de Anthropic es la fuente oficial.
            Periodo en hora local ({{ \App\Support\Fecha::zona() }}): {{ $r['desde']->format('d/m/Y') }} al {{ $r['hasta']->format('d/m/Y') }}.
            @if ($r['total']['costo'] !== null && ! $r['total']['costo_incompleto'] && abs($r['total']['registrado'] - $r['total']['costo']) > 0.01)
                <br>El costo guardado al momento de cada validacion suma {{ $usd($r['total']['registrado']) }}. Ese valor se congelo con la tarifa configurada entonces; hasta el 25/09/2026 Sonnet 5 figuraba a US$ 3/15 por millon en lugar de 2/10.
            @endif
        </div>
    @endif
</div>
</x-filament-panels::page>
