<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class InsDwpStandardPVPolicy
{
    /**
     * Create a new policy instance.
     */
    public function __construct()
    {
        //
    }

    public function manage(User $user): Response
    {
        $auth = $user->ins_dwp_auths->first();
        $actions = json_decode($auth->actions ?? '{}', true);

        return in_array('manage-standards', $actions) || in_array('manage-devices', $actions)
        ? Response::allow()
        : Response::deny(__('Kamu tak memiliki wewenang untuk mengelola standar PV'));
    }

    public function before(User $user): ?bool
    {
        return $user->id == 1 ? true : null;
    }
}
