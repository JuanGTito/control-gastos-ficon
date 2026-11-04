<?php

namespace App\Modules\Expenses\Http;

use App\Models\User;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Catalogs\Models\CatalogItem;
use App\Modules\Expenses\Actions\SaveExpense;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Queries\ExpenseFilters;
use App\Modules\PettyCash\Models\PettyCashAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ExpenseController
{
    public function index(Request $request, ExpenseFilters $filterService)
    {
        $filters = $filterService->read($request);
        $query = $filterService->query($request, $filters);
        $today = now(config('expenses.timezone'));
        $visible = Expense::query()->visibleTo($request->user());
        $totals = [
            'filtered' => $filterService->total(clone $query),
            'today' => $filterService->total((clone $visible)->whereDate('spent_on', $today->toDateString())),
            'week' => $filterService->total((clone $visible)->whereBetween('spent_on', [$today->copy()->startOfWeek()->toDateString(), $today->copy()->endOfWeek()->toDateString()])),
            'count' => (clone $query)->count(),
        ];
        $expenses = $query->with(['user', 'categoryItem', 'receiptType', 'cash'])->orderByDesc('spent_on')->orderByDesc('id')->paginate(12)->withQueryString();
        $categories = CatalogItem::ofKind('category')->get();
        $receiptTypes = CatalogItem::ofKind('receipt_type')->get();
        $cashAccounts = PettyCashAccount::visibleTo($request->user())->with('user')->orderByDesc('id')->get();
        $people = $request->user()->canViewAllExpenses() ? User::operational()->orderBy('name')->get(['id', 'name']) : collect();

        return view('expenses.index', compact('expenses', 'filters', 'totals', 'today', 'categories', 'receiptTypes', 'cashAccounts', 'people'));
    }

    public function store(ExpenseRequest $request, SaveExpense $save)
    {
        $expense = $save->execute($request->user(), $request->validated(), $request->file('receipt'));

        return redirect()->route('expenses.index', array_filter(['petty_cash_account_id' => $expense->petty_cash_account_id]))->with('status', 'Gasto registrado correctamente.');
    }

    public function update(ExpenseRequest $request, Expense $expense, SaveExpense $save)
    {
        Gate::authorize('update', $expense);
        $save->execute($request->user(), $request->validated(), $request->file('receipt'), $expense);

        return redirect()->route('expenses.index')->with('status', 'Gasto actualizado. El cambio quedó registrado en la auditoría.');
    }

    public function receipt(Expense $expense)
    {
        Gate::authorize('view', $expense);
        abort_unless($expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->download($expense->receipt_path, $expense->receipt_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function history(Expense $expense)
    {
        Gate::authorize('view', $expense);
        $entries = AuditEntry::with('actor')->where('subject_type', 'expense')->where('subject_id', $expense->id)->orderByDesc('id')->paginate(20);

        return view('audit.history', compact('expense', 'entries'));
    }
}
