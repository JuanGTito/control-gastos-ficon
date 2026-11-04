<?php

namespace App\Modules\PettyCash\Policies;

use App\Models\User;
use App\Modules\PettyCash\Models\PettyCashAccount;

class PettyCashPolicy
{
    public function view(User $user, PettyCashAccount $cash): bool
    {
        return $user->active && ($user->canViewAllExpenses() || $user->id === $cash->user_id);
    }
}
