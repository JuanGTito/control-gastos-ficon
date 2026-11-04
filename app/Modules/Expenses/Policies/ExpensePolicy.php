<?php

namespace App\Modules\Expenses\Policies;

use App\Models\User;
use App\Modules\Expenses\Models\Expense;

class ExpensePolicy
{
    public function view(User $user, Expense $expense): bool
    {
        return $user->active && ($user->canViewAllExpenses() || $expense->user_id === $user->id);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->canRecordExpenses() && $this->view($user, $expense);
    }
}
