<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Catalogs\Models\CatalogItem;
use App\Modules\PettyCash\Models\PettyCashAccount;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class RenditionWorkbook
{
    public function build(Collection $expenses, array $filters, User $exporter, ?PettyCashAccount $fullCash = null): Spreadsheet
    {
        $path = config('expenses.template_path');
        if (! is_file($path)) {
            throw new RuntimeException('Falta la plantilla de rendición en storage/app/templates/caja-chica.xlsx.');
        }
        $book = IOFactory::load($path);
        $prototype = clone $book->getSheet(0);
        $groups = $expenses->groupBy(fn ($expense) => $expense->petty_cash_account_id ? 'cash-'.$expense->petty_cash_account_id : 'user-'.$expense->user_id);
        if ($groups->isEmpty()) {
            $groups = collect(['empty' => collect()]);
        }
        $baseRows = [];
        $page = 0;
        foreach ($groups as $rows) {
            $owner = $fullCash?->user ?? $rows->first()?->user ?? (! empty($filters['user_id']) ? User::findOrFail($filters['user_id']) : $exporter);
            $cash = $fullCash ?? $rows->first()?->cash;
            $sheet = $page === 0 ? $book->getSheet(0) : clone $prototype;
            if ($page > 0) {
                $sheet->setTitle('Rendición '.($page + 1));
                $book->addSheet($sheet, $page);
            }
            $this->fillForm($sheet, $rows, $owner, $cash, $fullCash !== null, $filters, $baseRows);
            $page++;
        }
        $base = $book->getSheetByName('BASE_RENDICIONES');
        $this->fillBase($base, $baseRows);
        $this->fillConfiguration($book);
        if ($fullCash?->is_permanent) {
            $this->fillFundingHistory($book, $fullCash);
        }
        $book->setActiveSheetIndex(0);
        $book->getProperties()->setCreator($exporter->name)->setTitle($fullCash ? 'Rendición '.$fullCash->code : 'Consulta filtrada de gastos')->setDescription($this->filterLabel($filters));

        return $book;
    }

    private function text(Worksheet $sheet, string $cell, mixed $value): void
    {
        $sheet->setCellValueExplicit($cell, $value === null ? '' : (string) $value, DataType::TYPE_STRING);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('@');
    }

    private function date(Worksheet $sheet, string $cell, $date): void
    {
        if ($date) {
            $sheet->setCellValue($cell, Date::PHPToExcel($date))->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        } else {
            $sheet->setCellValue($cell, null);
        }
    }

    public function filterLabel(array $filters): string
    {
        $labels = [];
        foreach (['from' => 'Desde', 'to' => 'Hasta', 'category' => 'Categoría', 'q' => 'Búsqueda'] as $key => $label) {
            if (! empty($filters[$key])) {
                $labels[] = $label.': '.$filters[$key];
            }
        }

        return implode(' · ', $labels) ?: 'Todos los movimientos autorizados de la consulta';
    }

    private function fillForm(Worksheet $sheet, Collection $rows, User $owner, ?PettyCashAccount $cash, bool $full, array $filters, array &$baseRows): void
    {
        $extra = max(0, $rows->count() - 20);
        if ($extra) {
            $sheet->insertNewRowBefore(41, $extra);
            for ($row = 41; $row < 41 + $extra; $row++) {
                for ($col = 1; $col <= 12; $col++) {
                    $sheet->duplicateStyle($sheet->getStyle([$col, 40]), Coordinate::stringFromColumnIndex($col).$row);
                }
                foreach (['F', 'G', 'K'] as $col) {
                    $sheet->setDataValidation($col.$row, clone $sheet->getDataValidation($col.'21'));
                }
            }
        }
        $last = 40 + $extra;
        for ($row = 21; $row <= $last; $row++) {
            for ($col = 1; $col <= 31; $col++) {
                $sheet->setCellValue([$col, $row], null);
            }
        }
        foreach (['C6', 'C7', 'C8', 'C9', 'C10', 'H6', 'H7', 'H8', 'H9', 'E13', 'E16', 'A'.(51 + $extra), 'E'.(51 + $extra), 'I'.(51 + $extra), 'A'.(53 + $extra), 'E'.(53 + $extra), 'I'.(53 + $extra)] as $cell) {
            $sheet->setCellValue($cell, null);
        }
        $this->text($sheet, 'A1', config('expenses.company'));
        $sheet->getHeaderFooter()->setOddHeader('&L&"Arial,Bold"'.str_replace('&', '&&', config('expenses.company')).'&RRendición de caja chica');
        $this->text($sheet, 'C6', $cash?->code ?? 'CONSULTA-'.$owner->id);
        $this->text($sheet, 'C7', $owner->name);
        $this->text($sheet, 'C8', $cash?->dni);
        $this->text($sheet, 'C9', $cash?->branch?->name);
        $this->text($sheet, 'H8', $cash?->area);
        $this->text($sheet, 'H9', $cash?->deliverer?->name);
        $this->date($sheet, 'H6', $cash?->received_on);
        $renderDate = $full ? ($cash->submitted_at ?? now()) : now();
        $this->date($sheet, 'H7', $renderDate->copy()->timezone(config('expenses.timezone')));
        $this->text($sheet, 'C10', $full ? $cash->purpose : 'CONSULTA FILTRADA. '.($cash?->purpose ? $cash->purpose.'. ' : '').$this->filterLabel($filters));
        $sheet->getRowDimension(10)->setRowHeight($full ? 30 : 42);
        $sheet->getStyle('C10:L10')->getAlignment()->setWrapText(true);
        if ($full) {
            $sheet->setCellValueExplicit('E13', $cash->received_amount, DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('E16', $cash->figures()['returned'], DataType::TYPE_NUMERIC);
        }
        $symbol = config('expenses.currency') === 'PEN' ? 'S/' : config('expenses.currency');
        $moneyFormat = '"'.str_replace('"', '', $symbol).' "#,##0.00;[Red]-"'.str_replace('"', '', $symbol).' "#,##0.00';
        foreach (['E13', 'E14', 'E15', 'E16', 'E17', 'J'.(41 + $extra), 'E'.(45 + $extra)] as $cell) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($moneyFormat);
        }
        foreach ($rows->values() as $index => $expense) {
            $row = 21 + $index;
            $sheet->setCellValue('A'.$row, $index + 1);
            $this->date($sheet, 'B'.$row, $expense->spent_on);
            $values = ['C' => $expense->provider, 'D' => $expense->tax_id, 'E' => $expense->description, 'F' => $expense->category_label, 'G' => $expense->receiptType?->name, 'H' => $expense->series, 'I' => $expense->document_number, 'K' => $expense->receipt_path ? 'Sí' : 'No', 'L' => $expense->observation];
            foreach ($values as $col => $value) {
                $this->text($sheet, $col.$row, $value);
            }
            $sheet->setCellValueExplicit('J'.$row, $expense->amount, DataType::TYPE_NUMERIC);
            $sheet->getStyle('J'.$row)->getNumberFormat()->setFormatCode($moneyFormat);
            $sheet->getStyle('A'.$row.':L'.$row)->getAlignment()->setWrapText(true)->setVertical('center');
            $sheet->getRowDimension($row)->setRowHeight(max(28, 12 * max(ceil(mb_strlen($expense->description) / 28), ceil(mb_strlen($expense->provider ?? '') / 23), ceil(mb_strlen($expense->observation ?? '') / 24)) + 8));
            $record = [$cash?->code ?? 'CONSULTA-'.$owner->id, Date::PHPToExcel($renderDate), $owner->name, $cash?->dni, $cash?->area, $cash?->branch?->name, $cash?->purpose, Date::PHPToExcel($expense->spent_on), $expense->provider, $expense->tax_id, $expense->description, $expense->category_label, $expense->receiptType?->name, $expense->series, $expense->document_number, $expense->amount, $expense->receipt_path ? 'Sí' : 'No', $expense->observation];
            $baseRows[] = $record;
            foreach ($record as $col => $value) {
                $numeric = in_array($col, [1, 7, 15], true);
                $sheet->setCellValueExplicit([$col + 14, $row], $value ?? '', $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                if (! $numeric) {
                    $sheet->getStyle([$col + 14, $row])->getNumberFormat()->setFormatCode('@');
                }
            }
        }
        $sheet->setCellValue('J'.(41 + $extra), '=ROUND(SUM(J21:J'.$last.'),2)');
        $sheet->setCellValue('E14', '=J'.(41 + $extra));
        $sheet->setCellValue('E15', '=IF(E13="","",ROUND(E13-E14,2))');
        $sheet->setCellValue('E17', '=IF(E13="","",ROUND(E15-E16,2))');
        if ($full && $cash->is_permanent) {
            $this->text($sheet, 'A13', 'TOTAL ENTREGADO POR ADMINISTRACIÓN');
            $this->text($sheet, 'A15', '(=) SALDO NETO ACUMULADO');
            $this->text($sheet, 'A16', '(-) DEVOLUCIONES EXCEPCIONALES');
            $this->text($sheet, 'A17', '(=) SALDO ACTUAL / ADELANTO PENDIENTE');
            $this->text($sheet, 'G13', 'CAJA PERMANENTE');
            $sheet->setCellValue('G15', '=IF(E17<0,"ADELANTO PERSONAL PENDIENTE","SALDO DISPONIBLE")');
        } elseif ($full) {
            $sheet->setCellValue('G15', '=IF(E13="","COMPLETE EL MONTO RECIBIDO",IF(ROUND(E17,2)=0,"CONFORME","REVISAR DIFERENCIA"))');
        } else {
            $this->text($sheet, 'G15', 'CONSULTA FILTRADA');
        }
        $sheet->setCellValue('E'.(44 + $extra), '=COUNTIF(J21:J'.$last.',">0")');
        $sheet->setCellValue('E'.(45 + $extra), '=J'.(41 + $extra));
        $sheet->setCellValue('E'.(46 + $extra), '=COUNTIFS(J21:J'.$last.',">0",K21:K'.$last.',"Sí")');
        $sheet->setCellValue('E'.(47 + $extra), '=E'.(44 + $extra).'-E'.(46 + $extra));
        $sheet->setCellValue('G'.(44 + $extra), '=IF(E'.(44 + $extra).'=0,"",IF(E'.(47 + $extra).'>0,"REVISAR COMPROBANTES PENDIENTES","COMPROBANTES COMPLETOS"))');
        $this->text($sheet, 'N18', 'Filas de esta consulta: '.$rows->count());
        $this->text($sheet, 'N19', 'Base generada automáticamente; cada movimiento aparece una sola vez.');
        $this->text($sheet, 'A'.(51 + $extra), 'Nombre: '.($full ? $cash->deliverer->name : ''));
        $this->text($sheet, 'E'.(51 + $extra), 'Nombre: '.$owner->name);
        $this->text($sheet, 'I'.(51 + $extra), 'Nombre: '.($full && $cash->status === 'closed' ? $cash->reviewer?->name : ''));
        foreach (['A', 'E', 'I'] as $col) {
            $this->text($sheet, $col.(53 + $extra), 'Fecha:');
        }
        if ($full && $cash->is_permanent) {
            $this->text($sheet, 'A'.(56 + $extra), 'Caja permanente de '.$owner->name.'. El saldo se conserva entre entregas. Un saldo negativo representa dinero adelantado por el responsable. Cada nueva entrega del administrador cubre primero ese adelanto y el resto se agrega al fondo. El detalle de entregas y reembolsos está en la hoja ENTREGAS. Este documento no implica devolución del saldo ni firma automática.');
        } elseif ($full) {
            $this->text($sheet, 'A'.(56 + $extra), 'Declaro que los gastos registrados en esta rendición corresponden a actividades relacionadas con '.config('expenses.company').' y que los importes consignados son correctos. Asimismo, entrego los documentos sustentatorios disponibles y me comprometo a devolver el saldo no utilizado. Estado de revisión: '.PettyCashAccount::STATUSES[$cash->status].'.');
        } else {
            $this->text($sheet, 'A'.(56 + $extra), 'Esta consulta incluye únicamente los gastos del responsable que coinciden con los filtros indicados. No constituye el cierre ni la conciliación completa de una caja chica. Los importes recibidos y devueltos se muestran únicamente en la exportación de rendición completa.');
        }
        $sheet->getPageSetup()->setPrintArea('A1:L'.(58 + $extra))->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(20, 20);
        $sheet->setShowGridlines(false);
    }

    private function fillBase(Worksheet $sheet, array $rows): void
    {
        $sheet->removeTableCollection();
        foreach ($sheet->getRowIterator(6, max(6, $sheet->getHighestRow())) as $row) {
            foreach ($row->getCellIterator('A', 'R') as $cell) {
                $cell->setValue(null);
            }
        }
        foreach ($rows as $index => $values) {
            foreach ($values as $col => $value) {
                $numeric = in_array($col, [1, 7, 15], true);
                $sheet->setCellValueExplicit([$col + 1, $index + 6], $value ?? '', $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                if (! $numeric) {
                    $sheet->getStyle([$col + 1, $index + 6])->getNumberFormat()->setFormatCode('@');
                }
            }
        }
        $last = max(6, count($rows) + 5);
        $sheet->addTable(new Table('A5:R'.$last, 'tblRendiciones'));
        $sheet->getStyle('B6:B'.$last)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle('H6:H'.$last)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle('P6:P'.$last)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->freezePane('A6');
        $sheet->getPageSetup()->setPrintArea('A1:R'.$last);
    }

    private function fillConfiguration(Spreadsheet $book): void
    {
        $sheet = $book->getSheetByName('CONFIGURACIÓN');
        foreach (['A' => ['category', 'Lista_Categorias'], 'B' => ['receipt_type', 'Lista_Comprobantes'], 'D' => ['branch', 'Lista_Sucursales']] as $col => [$kind, $rangeName]) {
            $items = CatalogItem::ofKind($kind)->get();
            for ($row = 5; $row <= max(34, $items->count() + 4); $row++) {
                $sheet->setCellValue($col.$row, null);
            }
            foreach ($items as $index => $item) {
                $this->text($sheet, $col.($index + 5), $item->name);
            }
            $book->removeNamedRange($rangeName);
            $book->addNamedRange(new NamedRange($rangeName, $sheet, '$'.$col.'$5:$'.$col.'$'.max(5, $items->count() + 4)));
        }
        $this->text($sheet, 'A2', 'Catálogos del sistema, incluidos los valores históricos desactivados. Para administrar las opciones de registro, utiliza Configuración en la aplicación.');
    }

    private function fillFundingHistory(Spreadsheet $book, PettyCashAccount $cash): void
    {
        $sheet = $book->createSheet()->setTitle('ENTREGAS');
        $this->text($sheet, 'A1', 'ENTREGAS DEL ADMINISTRADOR · '.$cash->code.' · '.$cash->user->name);
        $sheet->mergeCells('A1:H1');
        $sheet->fromArray(['Fecha', 'Entregó', 'Importe recibido', 'Reembolso al responsable', 'Agregado al fondo', 'Saldo neto anterior', 'Saldo neto después', 'Observación'], null, 'A3');
        foreach ($cash->fundings()->with('actor')->orderBy('id')->get() as $index => $funding) {
            $row = $index + 4;
            $this->date($sheet, 'A'.$row, $funding->funded_on);
            $this->text($sheet, 'B'.$row, $funding->actor->name);
            foreach (['C' => 'amount', 'D' => 'reimbursement', 'E' => 'available_addition', 'F' => 'opening_balance', 'G' => 'closing_balance'] as $col => $field) {
                $sheet->setCellValueExplicit($col.$row, $funding->$field, DataType::TYPE_NUMERIC);
            }
            $this->text($sheet, 'H'.$row, $funding->note);
        }
        $last = max(4, $sheet->getHighestRow());
        $sheet->getStyle('C4:G'.$last)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A3:H3')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A3:H3')->getFill()->setFillType('solid')->getStartColor()->setRGB('20395F');
        foreach (['A' => 14, 'B' => 25, 'C' => 20, 'D' => 24, 'E' => 20, 'F' => 20, 'G' => 20, 'H' => 45] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle('A3:H'.$last)->getAlignment()->setWrapText(true);
        $sheet->freezePane('A4');
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setPrintArea('A1:H'.$last);
    }
}
