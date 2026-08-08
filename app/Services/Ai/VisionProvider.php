<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * El codigo de dominio nunca conoce al proveedor.
 *
 * Aunque hoy solo haya una implementacion real, la interfaz permite el driver
 * simulado y deja la puerta abierta a cambiar de proveedor sin tocar el motor.
 */
interface VisionProvider
{
    public function analyze(VisionRequest $request): VisionResponse;

    public function name(): string;
}
