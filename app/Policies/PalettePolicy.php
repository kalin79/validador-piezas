<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Palette;
use App\Models\User;

/**
 * Palette usa BelongsToBrand, asi que el scope global ya filtra el listado.
 * Esta politica agrega el eje que faltaba: quien puede editar, no solo quien
 * puede ver.
 */
class PalettePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->puede($user, 'knowledge.view');
    }

    public function view(User $user, Palette $palette): bool
    {
        return $this->puede($user, 'knowledge.view')
            && $this->alcanzaMarca($user, $palette->brand_id);
    }

    public function create(User $user): bool
    {
        return $this->puede($user, 'knowledge.edit');
    }

    public function update(User $user, Palette $palette): bool
    {
        return $this->puede($user, 'knowledge.edit')
            && $this->alcanzaMarca($user, $palette->brand_id);
    }

    /**
     * Una paleta borrada deja sin referencia a las reglas que la usan y a las
     * validaciones que se hicieron contra ella. Se desactiva con is_active.
     */
    public function delete(User $user, $model): bool
    {
        return false;
    }
}
