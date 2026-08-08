@php
    $r = $this->resumen;
    $reglas = $this->porRegla;

    $colorPrecision = static function (?float $p): string {
        if ($p === null) {
            return '#94a3b8';
        }

        return match (true) {
            $p >= 90 => '#16a34a',
            $p >= 70 => '#d97706',
            default => '#dc2626',
        };
    };
@endphp

<x-filament-panels::page>
    <style>
        .cm-cards { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
        .cm-card { border: 1px solid rgba(128,128,128,.18); border-radius: .875rem; padding: 1.125rem 1.25rem; }
        .cm-n { font-size: 2rem; font-weight: 700; line-height: 1; letter-spacing: -.02em; }
        .cm-l { font-size: .75rem; opacity: .55; margin-top: .375rem; }
        .cm-s { font-size: .6875rem; opacity: .4; margin-top: .375rem; line-height: 1.45; }

        .cm-sec { display: flex; align-items: center; gap: .625rem; margin: 2rem 0 .875rem;
                  font-size: .75rem; font-weight: 600; text-transform: uppercase;
                  letter-spacing: .06em; opacity: .5; }
        .cm-sec::after { content: ""; flex: 1; height: 1px; background: rgba(128,128,128,.18); }

        .cm-tabla { width: 100%; border-collapse: collapse; font-size: .8125rem; }
        .cm-tabla th { text-align: left; font-weight: 600; font-size: .6875rem; text-transform: uppercase;
                       letter-spacing: .04em; opacity: .5; padding: .5rem .625rem;
                       border-bottom: 1px solid rgba(128,128,128,.2); }
        .cm-tabla td { padding: .625rem; border-bottom: 1px solid rgba(128,128,128,.1); }
        .cm-tabla tr:last-child td { border-bottom: none; }
        .cm-code { font-family: ui-monospace, monospace; font-weight: 600; }
        .cm-bar { height: 5px; border-radius: 3px; background: rgba(128,128,128,.15);
                  overflow: hidden; margin-top: .3125rem; min-width: 90px; }
        .cm-bar span { display: block; height: 100%; border-radius: 3px; }
        .cm-pct { font-weight: 700; }

        .cm-nota { font-size: .75rem; opacity: .6; line-height: 1.6; margin-top: 1rem;
                   padding: .875rem 1rem; border-radius: .625rem; background: rgba(128,128,128,.07); }
        .cm-vacio { text-align: center; padding: 3rem 1.5rem; }
        .cm-vacio h3 { font-size: 1rem; font-weight: 600; margin-bottom: .5rem; }
        .cm-vacio p { font-size: .875rem; opacity: .6; max-width: 460px; margin: 0 auto; line-height: 1.6; }
    </style>

    @if ($r['revisiones'] === 0)
        <div class="cm-vacio">
            <h3>Todavia no hay nada que medir</h3>
            <p>
                Estas metricas salen de las revisiones humanas. Entra a
                <strong>Operacion &rarr; Revision</strong>, juzga algunas validaciones y vuelve aqui.
                Con veinte o treinta ya se empieza a ver que reglas estan mal escritas.
            </p>
        </div>
    @else
        <div class="cm-cards">
            <div class="cm-card">
                <div class="cm-n" style="color: {{ $colorPrecision($r['precision']) }}">
                    {{ $r['precision'] !== null ? $r['precision'].'%' : '—' }}
                </div>
                <div class="cm-l">Precision</div>
                <div class="cm-s">De lo que el motor reporto, cuanto era real.</div>
            </div>

            <div class="cm-card">
                <div class="cm-n" style="color: {{ $colorPrecision($r['cobertura']) }}">
                    {{ $r['cobertura'] !== null ? $r['cobertura'].'%' : '—' }}
                </div>
                <div class="cm-l">Cobertura</div>
                <div class="cm-s">De todo lo que habia que ver, cuanto vio. Aproximada.</div>
            </div>

            <div class="cm-card">
                <div class="cm-n">{{ $r['revisiones'] }}</div>
                <div class="cm-l">Validaciones revisadas</div>
                <div class="cm-s">{{ $r['anulaciones'] }} con veredicto cambiado por el revisor.</div>
            </div>

            <div class="cm-card">
                <div class="cm-n" style="color:#dc2626">{{ $r['falsos'] }}</div>
                <div class="cm-l">Falsos positivos</div>
                <div class="cm-s">Hallazgos que el revisor descarto.</div>
            </div>

            <div class="cm-card">
                <div class="cm-n" style="color:#b45309">{{ $r['agregados'] }}</div>
                <div class="cm-l">Se le escaparon</div>
                <div class="cm-s">Incumplimientos que agrego un revisor.</div>
            </div>
        </div>

        <div class="cm-sec">Precision por regla</div>

        @if (count($reglas) === 0)
            <div style="font-size:.8125rem; opacity:.55">
                Ninguna revision cito un codigo de regla todavia.
            </div>
        @else
            <table class="cm-tabla">
                <thead>
                    <tr>
                        <th>Regla</th>
                        <th>Precision</th>
                        <th style="text-align:center">Correctos</th>
                        <th style="text-align:center">Falsos positivos</th>
                        <th style="text-align:center">Se escaparon</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reglas as $g)
                        <tr>
                            <td class="cm-code">{{ $g['codigo'] }}</td>
                            <td>
                                <span class="cm-pct" style="color: {{ $colorPrecision($g['precision']) }}">
                                    {{ $g['precision'] !== null ? $g['precision'].'%' : '—' }}
                                </span>
                                <div class="cm-bar">
                                    <span style="width: {{ $g['precision'] ?? 0 }}%;
                                                 background: {{ $colorPrecision($g['precision']) }}"></span>
                                </div>
                            </td>
                            <td style="text-align:center">{{ $g['ok'] }}</td>
                            <td style="text-align:center; color:#dc2626">{{ $g['fp'] }}</td>
                            <td style="text-align:center; color:#b45309">{{ $g['escapados'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="cm-nota">
            Las reglas van de peor a mejor precision a proposito: arriba esta el trabajo por hacer.
            <br><br>
            Una regla con muchos <strong>falsos positivos</strong> casi nunca significa que el modelo
            sea malo. Significa que el enunciado es ambiguo y admite lecturas que tu no querias.
            La correccion es reescribirla y publicar una version nueva, no cambiar de modelo.
            <br><br>
            Una regla que <strong>se escapa</strong> seguido esta redactada de forma que el modelo no
            la reconoce en la pieza. Ahi ayuda agregar ejemplos concretos al enunciado.
        </div>
    @endif
</x-filament-panels::page>
