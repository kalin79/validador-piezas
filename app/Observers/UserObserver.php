<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    public function saved(User $user): void
    {
        $user->forgetAccessCache();

        // Desactivar a alguien tiene que cortar su acceso por la API tambien.
        if ($user->wasChanged('is_active') && ! $user->is_active) {
            $user->tokens()->delete();
        }
    }
}
