@php
    use App\Enums\Severity;
    use App\Enums\ValidationStatus;

    /**
     * Todo el calculo se hace aqui y el HTML de abajo solo recorre un arreglo
     * de cadenas ya listas.
     *
     * No es preferencia de estilo: la version anterior usaba directivas que
     * Blade analiza con expresiones regulares fragiles (forelse con funcion
     * flecha, class con el arreglo en varias lineas) y el conjunto rompia la
     * compilacion sin que el error apuntara a la causa. Con la logica
     * separada, cualquier fallo futuro es PHP normal y se lee.
     *
     * Por lo mismo, en este archivo no se escribe el simbolo de arroba dentro
     * de comentarios: Blade busca directivas en todo el texto.
     */
    $runs = $asset->validationRuns()
        ->with(['verdict', 'findings', 'clientRuleSet', 'brandRuleSet', 'triggeredBy', 'promptTemplate'])
        ->orderByDesc('created_at')
        ->get();

    $coloresVeredicto = [
        'success' => '#16a34a',
        'warning' => '#d97706',
        'danger' => '#dc2626',
        'gray' => '#6b7280',
    ];

    $severidades = [
        ['B', Severity::Blocking, '#ef4444'],
        ['M', Severity::Major, '#f59e0b'],
        ['m', Severity::Minor, '#3b82f6'],
        ['i', Severity::Info, '#6b7280'],
    ];

    // Se comparan dos cosas por separado: la norma (huella de las reglas) y el
    // juez (modelo). Mezclarlas en una sola marca haria imposible responder
    // cual de las dos explica un veredicto distinto sobre la misma pieza.
    $cronologico = $runs->reverse()->values();
    $cambioReglas = [];
    $cambioModelo = [];
    $anterior = null;

    foreach ($cronologico as $r) {
        if ($anterior !== null) {
            if ($r->resolution_hash !== $anterior->resolution_hash) {
                $cambioReglas[$r->id] = true;
            }

            if (filled($r->model_identifier) && $r->model_identifier !== $anterior->model_identifier) {
                $cambioModelo[$r->id] = true;
            }
        }

        $anterior = $r;
    }

    $filas = [];

    foreach ($runs as $run) {
        $meta = (array) ($run->deterministic_results ?? []);
        $v = $run->verdict;

        // Conteo por severidad, ya formateado.
        $conteos = [];

        foreach ($severidades as $par) {
            $n = $run->findings->where('severity', $par[1])->count();

            if ($n > 0) {
                $conteos[] = ['texto' => $n.$par[0], 'color' => $par[2]];
            }
        }

        // Conjuntos aplicados, con version y si ya estan retirados.
        $conjuntos = [];

        foreach ([$run->clientRuleSet, $run->brandRuleSet] as $rs) {
            if ($rs !== null) {
                $conjuntos[] = [
                    'texto' => $rs->name.' v'.$rs->version,
                    'retirado' => $rs->status?->value === 'retired',
                ];
            }
        }

        // Nombre corto del modelo: se quita el prefijo del proveedor y la fecha
        // del identificador, que no aportan en una tabla.
        $modelo = '—';

        if (filled($run->model_identifier)) {
            $modelo = str_replace('claude-', '', preg_replace('/-[0-9]{8}$/', '', $run->model_identifier));
        }

        $notasModelo = [];

        if (($meta['ai_simulated'] ?? false) === true) {
            $notasModelo[] = 'simulado';
        } elseif (($meta['ai_ran'] ?? false) !== true) {
            $notasModelo[] = 'sin IA';
        }

        if (($meta['model_requested'] ?? null) !== null) {
            $notasModelo[] = 'elegido a mano';
        }

        $filas[] = [
            'fecha' => $run->created_at?->format('d/m/Y'),
            'hora' => $run->created_at?->format('H:i:s'),
            'veredicto' => $v?->status->label() ?? 'Sin veredicto',
            'color' => $coloresVeredicto[$v?->status->color() ?? 'gray'] ?? '#6b7280',
            'fallida' => $run->status === ValidationStatus::Failed,
            'puntaje' => $v?->score !== null ? number_format((float) $v->score, 1) : '—',
            'conteos' => $conteos,
            'conjuntos' => $conjuntos,
            'reglas' => count($run->resolved_rules_snapshot ?? []),
            'prompt' => $run->promptTemplate
                ? $run->promptTemplate->key.' v'.$run->promptTemplate->version
                : null,
            'modelo' => $modelo,
            'notas' => $notasModelo,
            'huella' => substr((string) $run->resolution_hash, 0, 10),
            'quien' => $run->triggeredBy?->name ?? 'sistema',
            'costo' => $run->cost_usd !== null ? '$'.number_format((float) $run->cost_usd, 4) : null,
            'cambio_reglas' => isset($cambioReglas[$run->id]),
            'cambio_modelo' => isset($cambioModelo[$run->id]),
        ];
    }

    $criterios = $runs->pluck('resolution_hash')->filter()->unique()->count();
    $cuantosModelos = $runs->pluck('model_identifier')->filter()->unique()->count();
@endphp

<style>
    .hi-tabla { width: 100%; border-collapse: collapse; font-size: .8125rem; }
    .hi-tabla th { text-align: left; font-weight: 600; padding: .5rem .625rem;
                   border-bottom: 1px solid rgba(128,128,128,.25); white-space: nowrap; }
    .hi-tabla td { padding: .75rem .625rem; border-bottom: 1px solid rgba(128,128,128,.12);
                   vertical-align: top; }
    .hi-tabla tr:last-child td { border-bottom: none; }
    .hi-mono { font-family: ui-monospace, monospace; font-size: .75rem; opacity: .75; line-height: 1.5; }
    .hi-num { font-weight: 700; font-size: 1rem; }
    .hi-reglas { background: rgba(245,158,11,.07); }
    .hi-modelo { background: rgba(59,130,246,.05); }
    .hi-aviso { display: block; font-size: .6875rem; margin-top: .375rem;
                padding: .3125rem .5rem; border-radius: .375rem;
                background: rgba(245,158,11,.16); }
    .hi-aviso-azul { background: rgba(59,130,246,.16); }
    .hi-pill { display: inline-block; font-size: .6875rem; font-weight: 600;
               padding: .125rem .5rem; border-radius: .75rem; color: #fff; white-space: nowrap; }
    .hi-sev { display: inline-block; font-size: .6875rem; font-weight: 600;
              padding: .0625rem .375rem; border-radius: .25rem; margin-right: .25rem; color: #fff; }
    .hi-vacio { text-align: center; padding: 2rem 1rem; opacity: .6; }
    .hi-nota { font-size: .75rem; opacity: .65; margin-top: 1rem; line-height: 1.55; }
    .hi-cabecera { font-size: .8125rem; margin-bottom: .875rem; opacity: .8; }
    .hi-alerta { color: #b45309; }
</style>

@if (count($filas) === 0)
    <div class="hi-vacio">Esta pieza no tiene validaciones registradas.</div>
@else
    <div class="hi-cabecera">
        {{ count($filas) }} validacion(es) sobre el mismo archivo,
        evaluadas con <strong>{{ $criterios }}</strong> criterio(s) distinto(s)
        @if ($cuantosModelos > 1)
            y juzgadas por <strong>{{ $cuantosModelos }}</strong> modelos distintos
        @endif
        .
    </div>

    <table class="hi-tabla">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Veredicto</th>
                <th>Puntaje</th>
                <th>Hallazgos</th>
                <th>Criterio aplicado</th>
                <th>Modelo</th>
                <th>Huella</th>
                <th>Disparada por</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $f)
                <tr class="{{ $f['cambio_reglas'] ? 'hi-reglas' : ($f['cambio_modelo'] ? 'hi-modelo' : '') }}">
                    <td class="hi-mono">
                        {{ $f['fecha'] }}<br>{{ $f['hora'] }}
                    </td>

                    <td>
                        <span class="hi-pill" style="background: {{ $f['color'] }}">{{ $f['veredicto'] }}</span>

                        @if ($f['fallida'])
                            <div class="hi-mono" style="color:#ef4444">Ejecucion fallida</div>
                        @endif
                    </td>

                    <td class="hi-num">{{ $f['puntaje'] }}</td>

                    <td>
                        @if (count($f['conteos']) === 0)
                            <span class="hi-mono">ninguno</span>
                        @endif

                        @foreach ($f['conteos'] as $c)
                            <span class="hi-sev" style="background: {{ $c['color'] }}">{{ $c['texto'] }}</span>
                        @endforeach
                    </td>

                    <td class="hi-mono">
                        @foreach ($f['conjuntos'] as $c)
                            {{ $c['texto'] }}
                            @if ($c['retirado'])
                                <span class="hi-alerta">(retirado)</span>
                            @endif
                            <br>
                        @endforeach

                        {{ $f['reglas'] }} reglas efectivas

                        @if ($f['prompt'])
                            <br>prompt {{ $f['prompt'] }}
                        @endif
                    </td>

                    <td class="hi-mono">
                        {{ $f['modelo'] }}

                        @foreach ($f['notas'] as $nota)
                            <div class="hi-alerta">{{ $nota }}</div>
                        @endforeach

                        @if ($f['cambio_modelo'])
                            <span class="hi-aviso hi-aviso-azul">Modelo distinto al de la validacion anterior</span>
                        @endif
                    </td>

                    <td class="hi-mono">
                        {{ $f['huella'] }}…

                        @if ($f['cambio_reglas'])
                            <span class="hi-aviso">El criterio cambio respecto de la validacion anterior</span>
                        @endif
                    </td>

                    <td class="hi-mono">
                        {{ $f['quien'] }}
                        @if ($f['costo'])
                            <br>{{ $f['costo'] }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="hi-nota">
        Todas las filas corresponden al <strong>mismo archivo</strong>: revalidar no reemplaza la pieza,
        solo vuelve a evaluarla. Una pieza corregida se carga como pieza nueva y tiene su propio historial.
        <br><br>
        Ante un veredicto distinto sobre la misma pieza hay tres explicaciones posibles, y el historial
        las separa: <strong>cambio la norma</strong> (huella distinta, fila en ambar),
        <strong>cambio el juez</strong> (modelo distinto, fila en azul), o <strong>ninguna de las dos</strong>,
        y entonces la diferencia viene de la variabilidad del modelo sobre el mismo insumo.
        <br><br>
        Una fila marcada <em>elegido a mano</em> se lanzo pidiendo un modelo especifico en vez del
        configurado. Se registra a proposito: un veredicto obtenido eligiendo el evaluador no es
        comparable con el del flujo normal si no se dice.
    </p>
@endif
