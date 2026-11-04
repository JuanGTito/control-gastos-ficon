<?php

namespace App\Modules\Expenses\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAudit;
use App\Modules\Catalogs\Models\CatalogItem;
use App\Modules\Expenses\Models\Expense;
use App\Modules\PettyCash\Models\PettyCashAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SaveExpense
{
    public function __construct(private RecordAudit $audit) {}

    public function execute(User $actor, array $data, ?UploadedFile $receipt, ?Expense $expense = null): Expense
    {
        abort_unless($actor->canRecordExpenses(), 403);
        $path = $receipt?->store('receipts', 'local');
        try {
            return DB::transaction(function () use ($actor, $data, $receipt, $expense, $path) {
                $existing = $expense !== null;
                $targetCashId = array_key_exists('petty_cash_account_id', $data) ? ($data['petty_cash_account_id'] ?: null) : $expense?->petty_cash_account_id;
                $cashIds = array_values(array_unique(array_filter([$expense?->petty_cash_account_id, $targetCashId])));
                $lockedCash = PettyCashAccount::whereIn('id', $cashIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($lockedCash as $account) {
                    Gate::forUser($actor)->authorize('view', $account);
                    if (! $account->editable()) {
                        throw ValidationException::withMessages(['petty_cash_account_id' => 'Los gastos de una rendición presentada o cerrada no se pueden modificar.']);
                    }
                }
                $cash = $targetCashId ? $lockedCash->get($targetCashId) : null;
                if ($targetCashId && ! $cash) {
                    throw ValidationException::withMessages(['petty_cash_account_id' => 'La entrega seleccionada no existe.']);
                }
                $model = $existing ? Expense::query()->lockForUpdate()->findOrFail($expense->id) : new Expense;
                if ($existing) {
                    Gate::forUser($actor)->authorize('update', $model);
                    $previousCash = $model->petty_cash_account_id ? $lockedCash->get($model->petty_cash_account_id) : null;
                    if ($previousCash?->is_permanent && $previousCash->fundings()->where('expense_cutoff_id', '>=', $model->id)->exists() && (bccomp($data['amount'], $model->amount, 2) !== 0 || $targetCashId != $model->petty_cash_account_id || $data['spent_on'] !== $model->spent_on->toDateString())) {
                        throw ValidationException::withMessages(['expense' => 'El importe, la fecha y la caja de este gasto ya están conciliados en una entrega posterior. Puedes completar el comprobante y corregir los demás datos sin alterar el reembolso histórico.']);
                    }
                    if ($model->version !== (int) $data['version']) {
                        throw ValidationException::withMessages(['version' => 'Otra persona modificó este gasto. Cierra el formulario y recarga la página antes de editarlo.']);
                    }
                }
                $before = $existing ? $model->snapshot() : null;
                $ownerId = $existing ? $model->user_id : ($cash?->user_id ?? $actor->id);
                if ($cash && $cash->user_id !== $ownerId) {
                    throw ValidationException::withMessages(['petty_cash_account_id' => 'El responsable del gasto y de la caja deben coincidir.']);
                }
                if ($cash && $data['spent_on'] < $cash->received_on->toDateString()) {
                    throw ValidationException::withMessages(['spent_on' => 'El gasto no puede ser anterior a la entrega del dinero.']);
                }
                $category = CatalogItem::where('kind', 'category')->where('name_key', mb_strtolower($data['category']))->sharedLock()->first();
                if (! $category || (! $category->active && $model->category_id !== $category->id)) {
                    throw ValidationException::withMessages(['category' => 'Selecciona una categoría activa.']);
                }
                $typeId = array_key_exists('receipt_type_id', $data) ? ($data['receipt_type_id'] ?: null) : $model->receipt_type_id;
                if ($typeId) {
                    $type = CatalogItem::where('kind', 'receipt_type')->sharedLock()->find($typeId);
                    if (! $type || (! $type->active && $typeId != $model->receipt_type_id)) {
                        throw ValidationException::withMessages(['receipt_type_id' => 'Selecciona un tipo de comprobante activo.']);
                    }
                }
                $model->fill(collect($data)->only(['spent_on', 'description', 'amount', 'provider', 'tax_id', 'series', 'document_number', 'observation'])->all());
                $model->category = $category->name;
                $model->category_id = $category->id;
                $model->receipt_type_id = $typeId;
                $model->petty_cash_account_id = $targetCashId;
                if (! $existing) {
                    $model->user_id = $ownerId;
                    $model->currency = config('expenses.currency');
                    $model->version = 1;
                } else {
                    $model->version++;
                }
                if ($path) {
                    $model->receipt_path = $path;
                    $model->receipt_name = mb_substr(basename(str_replace('\\', '/', $receipt->getClientOriginalName())), 0, 200);
                }
                $model->save();
                foreach ($lockedCash as $account) {
                    $account->increment('version');
                }
                $this->audit->record($actor, 'expense', $model->id, $existing ? 'updated' : 'created', $before, $model->snapshot());

                return $model;
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }
}
