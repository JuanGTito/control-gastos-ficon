<?php

namespace App\Modules\PettyCash\Http;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAudit;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Catalogs\Models\CatalogItem;
use App\Modules\PettyCash\Actions\RegisterFunding;
use App\Modules\PettyCash\Models\PettyCashAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PettyCashController
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(PettyCashAccount::STATUSES))], 'user_id' => ['nullable', 'integer', 'exists:users,id']]);
        if (! $request->user()->canViewAllExpenses() && ! empty($filters['user_id'])) {
            abort_unless((int) $filters['user_id'] === $request->user()->id, 403);
        }
        $accounts = PettyCashAccount::visibleTo($request->user())->with(['user', 'branch'])->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))->orderByDesc('id')->paginate(12)->withQueryString();

        return view('petty-cash.index', ['accounts' => $accounts, 'filters' => $filters, 'people' => $request->user()->canViewAllExpenses() ? User::operational()->where('active', true)->whereIn('role', ['user', 'technician', 'admin'])->orderBy('name')->get() : collect(), 'branches' => CatalogItem::ofKind('branch')->where('active', true)->get()]);
    }

    public function show(PettyCashAccount $cash)
    {
        Gate::authorize('view', $cash);
        $cash->load(['user', 'deliverer', 'reviewer', 'branch']);

        return view('petty-cash.show', ['cash' => $cash, 'figures' => $cash->figures(), 'expenses' => $cash->expenses()->with(['categoryItem', 'receiptType'])->orderByDesc('spent_on')->paginate(12), 'returns' => $cash->returns()->with('actor')->orderByDesc('id')->get(), 'entries' => AuditEntry::with('actor')->where('subject_type', 'cash')->where('subject_id', $cash->id)->orderByDesc('id')->get(), 'branches' => CatalogItem::ofKind('branch')->get(), 'fundings' => $cash->fundings()->with('actor')->orderByDesc('id')->get(), 'reviews' => $cash->reviews()->with('actor')->orderByDesc('id')->get()]);
    }

    public function store(Request $request, RecordAudit $audit)
    {
        return $this->save($request, $audit);
    }

    public function update(Request $request, PettyCashAccount $cash, RecordAudit $audit)
    {
        return $this->save($request, $audit, $cash);
    }

    private function save(Request $request, RecordAudit $audit, ?PettyCashAccount $cash = null)
    {
        abort_unless($request->user()->managesExpenses(), 403);
        $data = $request->validate([
            'user_id' => [$cash ? 'nullable' : 'required', 'integer', Rule::exists('users', 'id')->where('active', true)->whereIn('role', ['user', 'technician', 'admin', 'superadmin'])],
            'purpose' => ['required', 'string', 'max:500'], 'dni' => ['nullable', 'regex:/^[0-9]{8,20}$/'], 'area' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('kind', 'branch')->where(fn ($q) => $q->where('active', true)->when($cash?->branch_id, fn ($q, $id) => $q->orWhere('id', $id)))],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('expenses.timezone'))->toDateString()],
            'received_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'version' => [$cash ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);
        $model = DB::transaction(function () use ($request, $data, $cash, $audit) {
            $model = $cash ? PettyCashAccount::lockForUpdate()->findOrFail($cash->id) : new PettyCashAccount;
            if (! $cash) {
                User::lockForUpdate()->findOrFail($data['user_id']);
                if (PettyCashAccount::where('user_id', $data['user_id'])->where('is_permanent', true)->exists()) {
                    throw ValidationException::withMessages(['user_id' => 'Esta persona ya tiene una caja permanente. Registra una nueva entrega dentro de su caja.']);
                }
            }
            if ($cash && (! $model->editable() || $model->version !== (int) $data['version'])) {
                throw ValidationException::withMessages(['cash' => 'La caja no admite cambios o su versión cambió. Recarga la página.']);
            }
            if ($cash && ! empty($data['user_id']) && (int) $data['user_id'] !== $model->user_id) {
                throw ValidationException::withMessages(['user_id' => 'El responsable de una entrega no se puede cambiar.']);
            }
            $before = $cash ? $model->snapshot() : null;
            if ($cash && $model->is_permanent && (bccomp($data['received_amount'], $model->received_amount, 2) !== 0 || $data['received_on'] !== $model->received_on->toDateString())) {
                throw ValidationException::withMessages(['received_amount' => 'Las entregas de dinero no se sobrescriben. Usa Registrar entrega para añadir una reposición auditada.']);
            }
            $model->fill(collect($data)->except(['version', 'user_id'])->all());
            if (! $cash) {
                $model->user_id = $data['user_id'];
                $model->delivered_by = $request->user()->id;
                $model->currency = config('expenses.currency');
                $model->is_permanent = true;
            }
            $model->version = $cash ? $model->version + 1 : 1;
            $model->save();
            if (! $cash) {
                $model->code = 'RC-'.str_pad((string) $model->id, 6, '0', STR_PAD_LEFT);
                $model->save();
            }
            $model->refresh();
            $earliest = $model->expenses()->min('spent_on');
            if ($earliest && $model->received_on->toDateString() > $earliest) {
                throw ValidationException::withMessages(['received_on' => 'La entrega no puede ser posterior a los gastos registrados.']);
            }
            $earliestReturn = $model->returns()->min('returned_on');
            if ($earliestReturn && $model->received_on->toDateString() > $earliestReturn) {
                throw ValidationException::withMessages(['received_on' => 'La entrega no puede ser posterior a una devolución registrada.']);
            }
            $audit->record($request->user(), 'cash', $model->id, $cash ? 'updated' : 'created', $before, $model->snapshot());
            if (! $cash) {
                app(RegisterFunding::class)->execute($request->user(), $model, ['amount' => $data['received_amount'], 'funded_on' => $data['received_on'], 'request_key' => (string) Str::uuid(), 'note' => 'Entrega inicial']);
                $model->refresh();
            }

            return $model;
        });

        return redirect()->route('cash.show', $model)->with('status', 'Entrega de caja chica guardada.');
    }

    public function returnMoney(Request $request, PettyCashAccount $cash, RecordAudit $audit)
    {
        Gate::authorize('view', $cash);
        abort_unless($request->user()->canRecordExpenses(), 403);
        $data = $request->validate(['amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'], 'returned_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$cash->received_on->toDateString(), 'before_or_equal:'.now(config('expenses.timezone'))->toDateString()], 'note' => ['nullable', 'string', 'max:500']]);
        DB::transaction(function () use ($request, $cash, $data, $audit) {
            $model = PettyCashAccount::lockForUpdate()->findOrFail($cash->id);
            if (! $model->editable()) {
                throw ValidationException::withMessages(['cash' => 'Las devoluciones solo se registran con la caja abierta u observada.']);
            }
            if ($data['returned_on'] < $model->received_on->toDateString()) {
                throw ValidationException::withMessages(['returned_on' => 'La devolución no puede ser anterior a la entrega.']);
            }
            if (bccomp($data['amount'], $model->figures()['difference'], 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'La devolución supera el dinero pendiente de devolver.']);
            }
            $entry = $model->returns()->create($data + ['actor_id' => $request->user()->id]);
            $model->increment('version');
            $audit->record($request->user(), 'cash', $model->id, 'returned', null, ['return_id' => $entry->id, 'amount' => $entry->amount, 'returned_on' => $entry->returned_on->toDateString(), 'note' => $entry->note]);
        });

        return redirect()->route('cash.show', $cash)->with('status', 'Devolución registrada y auditada.');
    }

    public function transition(Request $request, PettyCashAccount $cash, RecordAudit $audit)
    {
        Gate::authorize('view', $cash);
        abort_unless($request->user()->canRecordExpenses(), 403);
        if ($cash->is_permanent) {
            throw ValidationException::withMessages(['cash' => 'La caja permanente conserva su saldo entre entregas. Usa las revisiones del contador sin cerrar el fondo.']);
        }
        $data = $request->validate(['status' => ['required', Rule::in(['submitted', 'observed', 'closed'])], 'review_note' => ['nullable', 'string', 'max:2000'], 'version' => ['required', 'integer', 'min:1']]);
        abort_if($data['status'] !== 'submitted' && ! $request->user()->managesExpenses(), 403);
        DB::transaction(function () use ($request, $cash, $data, $audit) {
            $model = PettyCashAccount::lockForUpdate()->findOrFail($cash->id);
            if ($model->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['cash' => 'La caja cambió. Recarga antes de continuar.']);
            }
            $before = $model->snapshot();
            $figures = $model->figures();
            if ($data['status'] === 'submitted') {
                if (! $model->editable() || ($figures['count'] === 0 && bccomp($figures['returned'], '0', 2) === 0)) {
                    throw ValidationException::withMessages(['cash' => 'Registra gastos o una devolución antes de presentar una caja abierta u observada.']);
                }
                $model->submitted_at = now();
            } elseif ($data['status'] === 'observed') {
                if (! in_array($model->status, ['submitted', 'closed'], true) || empty(trim($data['review_note'] ?? ''))) {
                    throw ValidationException::withMessages(['review_note' => 'Indica el motivo para observar una rendición presentada o reabrir una cerrada.']);
                }
                $model->closed_at = null;
            } else {
                if ($model->status !== 'submitted' || bccomp($figures['difference'], '0', 2) !== 0 || $figures['missing'] !== 0) {
                    throw ValidationException::withMessages(['cash' => 'Para cerrar, la rendición debe estar presentada, sin diferencia de dinero y con todos sus comprobantes adjuntos.']);
                }
                $model->closed_at = now();
            }
            if ($data['status'] !== 'submitted') {
                $model->reviewed_by = $request->user()->id;
            }
            $model->status = $data['status'];
            $model->review_note = $data['review_note'] ?? null;
            $model->version++;
            $model->save();
            $audit->record($request->user(), 'cash', $model->id, 'status_changed', $before, $model->snapshot());
        });

        return redirect()->route('cash.show', $cash)->with('status', 'Estado de la rendición actualizado.');
    }

    public function fund(Request $request, PettyCashAccount $cash, RegisterFunding $register)
    {
        abort_unless($request->user()->managesExpenses(), 403);
        $data = $request->validate(['amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'], 'funded_on' => ['required', 'date_format:Y-m-d'], 'request_key' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:500']]);
        $register->execute($request->user(), $cash, $data);

        return redirect()->route('cash.show', $cash)->with('status', 'Entrega registrada. Primero se cubre el adelanto personal y el resto queda disponible en la caja.');
    }

    public function review(Request $request, PettyCashAccount $cash, RecordAudit $audit)
    {
        abort_unless($request->user()->active && $request->user()->canReviewCash(), 403);
        $data = $request->validate(['outcome' => ['required', Rule::in(['reviewed', 'observed'])], 'note' => ['required_if:outcome,observed', 'nullable', 'string', 'max:2000'], 'version' => ['required', 'integer']]);
        DB::transaction(function () use ($cash, $request, $data, $audit) {
            $account = PettyCashAccount::lockForUpdate()->findOrFail($cash->id);
            if (! $account->is_permanent || $account->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['cash' => 'La caja cambió o es una rendición histórica. Recarga la página.']);
            }
            $figures = $account->figures();
            if ($data['outcome'] === 'reviewed' && $figures['missing'] > 0) {
                throw ValidationException::withMessages(['cash' => 'Hay comprobantes pendientes. Registra una observación antes de dar los documentos por revisados.']);
            }
            $review = $account->reviews()->create(['actor_id' => $request->user()->id, 'outcome' => $data['outcome'], 'note' => $data['note'] ?? null, 'cash_version' => $account->version, 'figures' => $figures]);
            $audit->record($request->user(), 'cash', $account->id, 'reviewed', null, ['review_id' => $review->id, 'outcome' => $review->outcome, 'note' => $review->note, 'cash_version' => $review->cash_version]);
        });

        return redirect()->route('cash.show', $cash)->with('status', 'Revisión guardada. La caja conserva su saldo y sigue operativa.');
    }
}
