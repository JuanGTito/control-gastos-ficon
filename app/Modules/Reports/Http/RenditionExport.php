<?php

namespace App\Modules\Reports\Http;

use App\Models\User;
use App\Modules\PettyCash\Models\PettyCashAccount;
use App\Modules\Reports\Services\RenditionWorkbook;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RenditionExport
{
    public function download(Request $request, string $format, Collection $expenses, array $filters, ?PettyCashAccount $cash = null)
    {
        $limit = $format === 'pdf' ? min(config('expenses.pdf_export_limit'), config('expenses.export_limit')) : config('expenses.export_limit');
        if ($expenses->count() > $limit) {
            throw ValidationException::withMessages(['export' => 'Reduce la consulta: el límite de esta exportación es '.$limit.' gastos.']);
        }
        $filename = ($cash ? $cash->code : 'consulta-gastos').'-'.now()->format('Y-m-d-His').'.'.$format;
        if ($format === 'xlsx') {
            $book = app(RenditionWorkbook::class)->build($expenses, $filters, $request->user(), $cash);

            return response()->streamDownload(function () use ($book) {
                (new Xlsx($book))->save('php://output');
                $book->disconnectWorksheets();
            }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
        }
        $groups = $expenses->groupBy(fn ($row) => $row->petty_cash_account_id ? 'cash-'.$row->petty_cash_account_id : 'user-'.$row->user_id);
        if ($groups->isEmpty()) {
            $groups = collect(['empty' => collect()]);
        }
        $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']));
        $fallbackOwner = ! empty($filters['user_id']) ? User::findOrFail($filters['user_id']) : $request->user();
        $pdf->loadHtml(view('reports.rendition-pdf', ['groups' => $groups, 'fullCash' => $cash, 'fallbackOwner' => $fallbackOwner, 'filtersLabel' => app(RenditionWorkbook::class)->filterLabel($filters)])->render());
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store']);
    }
}
