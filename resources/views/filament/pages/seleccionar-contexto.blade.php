<x-filament-panels::page>
    {{--
        Los estilos van embebidos a proposito: no dependen de que Tailwind
        escanee esta vista, asi que la pagina se ve bien con o sin tema
        personalizado compilado.
    --}}
    @push('styles')
        <style>
            .ctx-grid {
                display: grid;
                gap: 1.5rem;
            }

            @media (min-width: 768px) {
                .ctx-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            .ctx-field {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }

            .ctx-label {
                font-size: 0.875rem;
                font-weight: 500;
                line-height: 1.25rem;
            }

            .ctx-row {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.75rem;
            }

            .ctx-badges {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.5rem;
            }

            .ctx-hint {
                font-size: 0.875rem;
                line-height: 1.5rem;
                opacity: 0.65;
            }

            .ctx-warning {
                display: flex;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .ctx-warning-icon {
                flex-shrink: 0;
                width: 1.25rem;
                height: 1.25rem;
                margin-top: 0.125rem;
            }

            .ctx-warning-body p + p {
                margin-top: 0.25rem;
            }

            .ctx-actions {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.75rem;
                margin-top: 1.5rem;
            }

            .ctx-pending {
                font-size: 0.8125rem;
                line-height: 1.25rem;
                opacity: 0.7;
            }
        </style>
    @endpush

    @php
        $marcaActual = auth()->user()->activeBrand;
        $preview = $this->preview;
    @endphp

    {{-- 1. Contexto vigente --}}
    <x-filament::section>
        <x-slot name="heading">Contexto actual</x-slot>

        <x-slot name="description">
            Define en que marca caen las piezas que cargues. No cambia lo que puedes ver.
        </x-slot>

        <div class="ctx-row">
            @if ($marcaActual)
                <x-filament::badge color="info" size="lg">
                    {{ $marcaActual->fullName() }}
                </x-filament::badge>

                <span class="ctx-hint">
                    Toda pieza que cargues se validara contra las reglas de esta marca.
                </span>
            @else
                <x-filament::badge color="warning" size="lg">
                    Sin contexto
                </x-filament::badge>

                <span class="ctx-hint">
                    Elige un cliente y una marca antes de cargar piezas.
                </span>
            @endif
        </div>
    </x-filament::section>

    {{-- 2. Selector --}}
    <x-filament::section>
        <x-slot name="heading">Cambiar contexto</x-slot>

        @if ($this->clients->isEmpty())
            <p class="ctx-hint">
                No tienes acceso a ningun cliente. Pide que te agreguen a un equipo.
            </p>
        @else
            <div class="ctx-grid">
                <div class="ctx-field">
                    <label for="clientId" class="ctx-label">Cliente</label>

                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="clientId" id="clientId">
                            <option value="">Selecciona un cliente</option>
                            @foreach ($this->clients as $client)
                                <option value="{{ $client->id }}">{{ $client->name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div class="ctx-field">
                    <label for="brandId" class="ctx-label">Marca</label>

                    <x-filament::input.wrapper :disabled="blank($clientId)">
                        <x-filament::input.select
                            wire:model.live="brandId"
                            id="brandId"
                            :disabled="blank($clientId)"
                        >
                            <option value="">
                                {{ filled($clientId) ? 'Selecciona una marca' : 'Elige primero un cliente' }}
                            </option>
                            @foreach ($this->brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>

            <div class="ctx-actions">
                <x-filament::button
                    wire:click="guardar"
                    wire:loading.attr="disabled"
                    icon="heroicon-o-check"
                >
                    Usar esta marca
                </x-filament::button>

                @if ($this->hayCambioPendiente)
                    <span class="ctx-pending">
                        Seleccion sin aplicar. El contexto cambia recien al confirmar.
                    </span>
                @endif
            </div>
        @endif
    </x-filament::section>

    {{-- 3. Vista previa de las reglas efectivas --}}
    @if ($preview)
        <x-filament::section>
            <x-slot name="heading">Reglas que aplicarian en esta marca</x-slot>

            <x-slot name="description">
                Resultado de fusionar el conjunto corporativo del cliente con el de la marca.
            </x-slot>

            @if ($preview['total'] === 0)
                <div class="ctx-warning">
                    <x-filament::icon
                        icon="heroicon-o-exclamation-triangle"
                        class="ctx-warning-icon"
                        style="color: rgb(var(--warning-500, 245 158 11));"
                    />

                    <div class="ctx-warning-body">
                        <p><strong>Esta marca no tiene reglas publicadas.</strong></p>
                        <p class="ctx-hint">
                            Validar una pieza aqui no produciria ningun hallazgo y quedaria
                            aprobada por omision. Publica un conjunto de reglas antes de cargar.
                        </p>
                    </div>
                </div>
            @else
                <div class="ctx-badges">
                    <x-filament::badge color="primary" size="lg">
                        {{ $preview['total'] }} en total
                    </x-filament::badge>

                    @if ($preview['heredadas'] > 0)
                        <x-filament::badge color="warning">
                            {{ $preview['heredadas'] }} heredadas del cliente
                        </x-filament::badge>
                    @endif

                    @if ($preview['propias'] > 0)
                        <x-filament::badge color="info">
                            {{ $preview['propias'] }} propias de la marca
                        </x-filament::badge>
                    @endif

                    @if ($preview['sobrescritas'] > 0)
                        <x-filament::badge color="danger">
                            {{ $preview['sobrescritas'] }} sobrescritas
                        </x-filament::badge>
                    @endif
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
