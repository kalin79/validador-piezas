<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;

/**
 * El rol uploader existe para el caso del disenador externo: sube sus piezas y
 * ve sus resultados, no los de sus companeros. De ahi la distincion entre
 * submission.view_own y submission.view_brand, que hasta ahora no se aplicaba
 * en ningun lado.
 */
class SubmissionPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->puede($user, 'submission.view_own')
            || $this->puede($user, 'submission.view_brand')
            || $this->puede($user, 'submission.view_any');
    }

    public function view(User $user, Submission $submission): bool
    {
        if (! $this->alcanzaMarca($user, $submission->brand_id)) {
            return false;
        }

        if ($this->puede($user, 'submission.view_any')
            || $this->puede($user, 'submission.view_brand')) {
            return true;
        }

        return $this->puede($user, 'submission.view_own')
            && (int) $submission->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $this->puede($user, 'submission.create');
    }

    public function update(User $user, Submission $submission): bool
    {
        return $this->puede($user, 'submission.create')
            && $this->view($user, $submission);
    }

    /**
     * Una carga borrada se lleva sus validaciones. Es justo lo que un sistema
     * de auditoria no debe permitir.
     */
    public function delete(User $user, $model): bool
    {
        return false;
    }

    /**
     * Disparar una validacion cuesta dinero real en tokens, por eso tiene
     * permiso propio: el auditor puede ver todo y no puede gastar nada.
     */
    public function validar(User $user, Submission $submission): bool
    {
        return $this->puede($user, 'validation.trigger')
            && $this->view($user, $submission);
    }
}
