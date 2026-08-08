<x-filament-panels::page>
    <style>
        .tk-aviso { border-left: 3px solid #16a34a; background: rgba(22,163,74,.06); padding: 1rem 1.25rem; border-radius: .5rem; margin-bottom: 1.5rem; }
        .tk-token { font-family: ui-monospace, monospace; font-size: .8125rem; word-break: break-all; background: rgba(0,0,0,.06); padding: .75rem; border-radius: .375rem; margin: .75rem 0; }
        .tk-tabla { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .tk-tabla th { text-align: left; font-weight: 600; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; opacity: .6; padding: .5rem .75rem; }
        .tk-tabla td { padding: .75rem; border-top: 1px solid rgba(128,128,128,.18); vertical-align: middle; }
        .tk-nunca { font-size: .75rem; opacity: .55; }
        .tk-vacio { padding: 2rem; text-align: center; opacity: .6; font-size: .875rem; }
        .tk-nota { font-size: .8125rem; opacity: .75; line-height: 1.6; margin-top: 1.5rem; }
        .tk-nota code { font-family: ui-monospace, monospace; font-size: .75rem; background: rgba(128,128,128,.15); padding: .1rem .35rem; border-radius: .25rem; }
    </style>

    @if ($tokenNuevo)
        <div class="tk-aviso">
            <strong>Copialo ahora.</strong> No se vuelve a mostrar: el sistema guarda solo su huella.
            Si lo pierdes, revoca este y genera otro.

            <div class="tk-token">{{ $tokenNuevo }}</div>

            <x-filament::button size="sm" color="gray" wire:click="ocultarToken">
                Ya lo copie
            </x-filament::button>
        </div>
    @endif

    <x-filament::section>
        <x-slot name="heading">Tokens activos</x-slot>

        <x-slot name="description">
            Cada token da acceso a las mismas {{ $this->marcas }} marca(s) que ves tu en el panel.
            Si manana cambian tus accesos, cambian tambien los del token.
        </x-slot>

        @if ($this->tokens->isEmpty())
            <div class="tk-vacio">
                Todavia no tienes ninguno. Genera uno para conectar el plugin de Figma.
            </div>
        @else
            <table class="tk-tabla">
                <tr>
                    <th>Nombre</th>
                    <th>Creado</th>
                    <th>Ultimo uso</th>
                    <th></th>
                </tr>

                @foreach ($this->tokens as $token)
                    <tr>
                        <td><strong>{{ $token->nombre }}</strong></td>
                        <td>{{ $token->creado?->format('d/m/Y H:i') }}</td>
                        <td>
                            @if ($token->nuncaUsado)
                                <span class="tk-nunca">nunca usado</span>
                            @else
                                {{ $token->ultimoUso->diffForHumans() }}
                            @endif
                        </td>
                        <td style="text-align: right">
                            <x-filament::button
                                size="xs"
                                color="danger"
                                wire:click="revocar({{ $token->id }})"
                                wire:confirm="Revocar '{{ $token->nombre }}'? Quien lo tenga pierde el acceso de inmediato."
                            >
                                Revocar
                            </x-filament::button>
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </x-filament::section>

    <div class="tk-nota">
        <strong>Como se usa.</strong> El plugin lo envia en cada peticion como
        <code>Authorization: Bearer &lt;token&gt;</code>. La API admite 20 validaciones por minuto:
        cada una cuesta dinero real, y sin ese limite un bucle en el plugin vacia la cuenta en minutos.
        <br><br>
        <strong>Nunca lo pongas en el repositorio del plugin.</strong> Va en la configuracion local de
        quien lo usa. Si sospechas que se filtro, revocalo aqui y genera otro: es inmediato y no
        afecta a los demas.
    </div>
</x-filament-panels::page>
