<?php

namespace App\Modules\Reports\Http;

use App\Modules\Expenses\Queries\ExpenseFilters;
use App\Modules\PettyCash\Models\PettyCashAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExportController
{
    public function __invoke(Request $request, string $format, ExpenseFilters $service, RenditionExport $export)
    {
        $filters = $service->read($request);
        $query = $service->query($request, $filters);
        $limit = $format === 'pdf' ? min(config('expenses.pdf_export_limit'), config('expenses.export_limit')) : config('expenses.export_limit');
        if ((clone $query)->count() > $limit) {
            throw ValidationException::withMessages(['export' => 'Reduce la consulta: el límite de esta exportación es '.$limit.' gastos.']);
        }
        $expenses = $query->with(['user', 'categoryItem', 'receiptType', 'cash.user', 'cash.branch', 'cash.deliverer'])->orderBy('spent_on')->orderBy('id')->get();

        return $export->download($request, $format, $expenses, $filters);
    }

    public function cash(Request $request, PettyCashAccount $cash, string $format, RenditionExport $export)
    {
        Gate::authorize('view', $cash);
        $cash->load(['user', 'branch', 'deliverer', 'reviewer']);
        $limit = $format === 'pdf' ? min(config('expenses.pdf_export_limit'), config('expenses.export_limit')) : config('expenses.export_limit');
        if ($cash->expenses()->count() > $limit) {
            throw ValidationException::withMessages(['export' => 'Esta rendición supera el límite de '.$limit.' gastos para el formato solicitado.']);
        }
        $expenses = $cash->expenses()->with(['user', 'categoryItem', 'receiptType', 'cash'])->orderBy('spent_on')->orderBy('id')->get();

        return $export->download($request, $format, $expenses, [], $cash);
    }
}
