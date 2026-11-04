<?php

namespace App\Providers;

use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Policies\ExpensePolicy;
use App\Modules\PettyCash\Models\PettyCashAccount;
use App\Modules\PettyCash\Policies\PettyCashPolicy;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(PettyCashAccount::class, PettyCashPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Paginator::defaultView('components.pagination');
        Paginator::defaultSimpleView('components.pagination');
    }
}
