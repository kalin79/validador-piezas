<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Team;

/**
 * La cache de accesos vive 10 minutos. Sin esto, quitarle un cliente a un equipo
 * dejaria a sus miembros viendo marcas que ya no les corresponden hasta que expire.
 */
class TeamObserver
{
    public function saved(Team $team): void
    {
        $this->flush($team);
    }

    public function deleted(Team $team): void
    {
        $this->flush($team);
    }

    private function flush(Team $team): void
    {
        $team->users()->cursor()->each(
            fn ($user) => $user->forgetAccessCache()
        );
    }
}
