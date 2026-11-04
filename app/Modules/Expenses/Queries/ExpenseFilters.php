<?php

namespace App\Modules\Expenses\Queries;

use App\Modules\Catalogs\Models\CatalogItem;
use App\Modules\Expenses\Models\Expense;
use App\Modules\PettyCash\Models\PettyCashAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ExpenseFilters
{
    public function total($query): string
    {
        return bcadd((string) $query->toBase()->selectRaw('COALESCE(ROUND(SUM(amount), 2), 0) AS total')->first()->total, '0', 2);
    }

    public function read(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'category' => ['nullable', 'string', 'max:100', Rule::exists('catalog_items', 'name')->where('kind', 'category')],
            'q' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'petty_cash_account_id' => ['nullable', 'integer', 'exists:petty_cash_accounts,id'],
        ]);
        if (! empty($data['user_id']) && ! $request->user()->canViewAllExpenses()) {
            abort_unless((int) $data['user_id'] === $request->user()->id, 403);
        }
        if (! empty($data['petty_cash_account_id'])) {
            Gate::authorize('view', PettyCashAccount::findOrFail($data['petty_cash_account_id']));
        }

        return array_merge(['from' => null, 'to' => null, 'category' => null, 'q' => null, 'user_id' => null, 'petty_cash_account_id' => null], $data);
    }

    public function query(Request $request, array $filters)
    {
        return Expense::query()->visibleTo($request->user())
            ->when($filters['from'], fn ($q, $date) => $q->whereDate('spent_on', '>=', $date))
            ->when($filters['to'], fn ($q, $date) => $q->whereDate('spent_on', '<=', $date))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category_id', CatalogItem::where('kind', 'category')->where('name', $category)->value('id')))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['petty_cash_account_id'] ?? null, fn ($q, $id) => $q->where('petty_cash_account_id', $id))
            ->when($filters['q'], fn ($q, $term) => $q->whereRaw('LOWER(description) LIKE ? ESCAPE \'!\'', ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%']));
    }
}
