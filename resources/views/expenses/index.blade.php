@extends('layouts.app')
@section('title', 'Mis gastos')
@section('content')
<div class="page-heading"><div><span class="eyebrow">TU DINERO, CON MÁS CLARIDAD</span><h1>Mis gastos<span class="heading-dot">.</span></h1><p>Una mirada a tus gastos. Todo en su lugar.</p></div>@if(auth()->user()->canRecordExpenses())<button class="btn primary" data-new-expense><span aria-hidden="true">＋</span> Registrar gasto</button>@endif</div>
<div class="stats-grid">
    <article class="stat-card featured"><div class="stat-top"><span>Total de la consulta</span><span class="stat-symbol">↗</span></div><div class="stat-value"><small>{{ config('expenses.currency') }}</small> {{ number_format($totals['filtered'], 2) }}</div><span class="stat-caption">{{ $totals['count'] }} {{ $totals['count'] === 1 ? 'gasto encontrado' : 'gastos encontrados' }}</span></article>
    <article class="stat-card"><div class="stat-top"><span>Gastos de hoy</span><span class="stat-symbol">☀</span></div><div class="stat-value"><small>{{ config('expenses.currency') }}</small> {{ number_format($totals['today'], 2) }}</div><span class="stat-caption">{{ $today->format('d/m/Y') }} · todos los gastos de hoy</span></article>
    <article class="stat-card"><div class="stat-top"><span>Esta semana</span><span class="stat-symbol">▦</span></div><div class="stat-value"><small>{{ config('expenses.currency') }}</small> {{ number_format($totals['week'], 2) }}</div><span class="stat-caption">Lunes a domingo · semana actual</span></article>
</div>
<section class="panel expense-panel" aria-labelledby="expense-list-title">
    <div class="panel-heading"><div><h2 id="expense-list-title">Historial de gastos <span class="count-badge">{{ $totals['count'] }}</span></h2><p>Consulta, organiza y descarga tus movimientos.</p></div><div class="export-actions"><a class="btn secondary small" href="{{ route('reports.export', ['format' => 'xlsx'] + array_filter($filters)) }}">↓ Excel</a><a class="btn secondary small" href="{{ route('reports.export', ['format' => 'pdf'] + array_filter($filters)) }}">↓ PDF</a></div></div>
    <div class="quick-filters" aria-label="Períodos rápidos"><a @class(['chip', 'selected' => !$filters['from'] && !$filters['to']]) href="{{ route('expenses.index') }}">Todos</a><a class="chip" href="{{ route('expenses.index', ['from' => $today->toDateString(), 'to' => $today->toDateString()]) }}">Hoy</a><a class="chip" href="{{ route('expenses.index', ['from' => $today->copy()->startOfWeek()->toDateString(), 'to' => $today->copy()->endOfWeek()->toDateString()]) }}">Esta semana</a><a class="chip" href="{{ route('expenses.index', ['from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->copy()->endOfMonth()->toDateString()]) }}">Este mes</a></div>
    <form class="filter-form" method="get" action="{{ route('expenses.index') }}">
        <label class="search-field">Buscar<input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Buscar por descripción…" maxlength="100"></label>
        <label>Desde<input type="date" name="from" value="{{ $filters['from'] }}"></label>
        <label>Hasta<input type="date" name="to" value="{{ $filters['to'] }}"></label>
        <label>Categoría<select name="category"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category->name }}" @selected($filters['category'] === $category->name)>{{ $category->name }}{{ !$category->active ? ' (histórica)' : '' }}</option>@endforeach</select></label>
        @if(auth()->user()->canViewAllExpenses())<label>Responsable<select name="user_id"><option value="">Todos</option>@foreach($people as $person)<option value="{{ $person->id }}" @selected($filters['user_id'] == $person->id)>{{ $person->name }}</option>@endforeach</select></label>@endif
        <label>Caja chica<select name="petty_cash_account_id"><option value="">Todos los gastos</option>@foreach($cashAccounts as $account)<option value="{{ $account->id }}" @selected($filters['petty_cash_account_id'] == $account->id)>{{ $account->code }} · {{ $account->user->name }}</option>@endforeach</select></label>
        <button class="btn secondary" type="submit">Filtrar</button><a class="reset-link" href="{{ route('expenses.index') }}">Limpiar</a>
    </form>
    @if($expenses->isEmpty())
    <div class="empty-state"><span class="empty-icon" aria-hidden="true">▤</span><h3>{{ array_filter($filters) ? 'No encontramos gastos con estos filtros' : 'Tus cuentas claras empiezan aquí' }}</h3><p>{{ array_filter($filters) ? 'Prueba con otro período o limpia la búsqueda.' : 'Registra tu primer gasto y empieza a conocer tus movimientos.' }}</p>@if(!array_filter($filters) && auth()->user()->canRecordExpenses())<button class="btn primary" data-new-expense>＋ Registrar mi primer gasto</button>@else<a class="btn secondary" href="{{ route('expenses.index') }}">Ver todos los gastos</a>@endif</div>
    @else
    <div class="table-scroll"><table class="expense-table expense-records"><thead><tr><th>Fecha</th><th>Descripción</th><th>Categoría</th><th>Registrado por</th><th class="amount-cell">Importe</th><th>Acciones</th></tr></thead><tbody>
    @foreach($expenses as $expense)
    <tr><td data-label="Fecha" class="date-cell">{{ $expense->spent_on->format('d/m/Y') }}</td><td data-label="Descripción"><strong class="expense-description">{{ $expense->description }}</strong>@if($expense->cash)<a class="receipt-link" href="{{ route('cash.show', $expense->cash) }}">{{ $expense->cash->code }}</a>@endif @if($expense->receipt_path)<a class="receipt-link" href="{{ route('expenses.receipt', $expense) }}">↳ Comprobante adjunto</a>@else<span class="receipt-missing">Sin comprobante</span>@endif</td><td data-label="Categoría"><span class="category-tag">{{ $expense->category_label }}</span></td><td data-label="Responsable">{{ $expense->user->name }}</td><td data-label="Importe" class="amount-cell"><strong>{{ $expense->currency }} {{ number_format($expense->amount, 2) }}</strong></td><td data-label="Acciones"><div class="row-actions">@if(auth()->user()->canRecordExpenses() && (!$expense->cash || $expense->cash->editable()))<button class="text-button" data-edit-expense='{{ \Illuminate\Support\Js::encode(array_merge($expense->only(["id", "description", "amount", "version", "receipt_name", "petty_cash_account_id", "provider", "tax_id", "receipt_type_id", "series", "document_number", "observation"]), ["spent_on" => $expense->spent_on->format("Y-m-d"), "category" => $expense->category_label])) }}'>Editar</button>@endif<a class="text-button subtle" href="{{ route('expenses.history', $expense) }}">Historial</a></div></td></tr>
    @endforeach
    </tbody></table></div>
    {{ $expenses->links() }}
    @endif
    <div class="panel-footnote"><span aria-hidden="true">◷</span> Cada edición guarda quién hizo el cambio y cuándo. @if(auth()->user()->canViewAllExpenses())<span>Estás viendo los gastos de todos los usuarios.</span>@endif</div>
</section>
@endsection
@push('dialogs')
@if(auth()->user()->canRecordExpenses())
<dialog id="expense-dialog" class="modal" aria-labelledby="expense-dialog-title" @if($errors->any() && old('_form') === 'expense') data-reopen @endif>
    <form id="expense-form" method="post" action="{{ old('_expense_id') ? route('expenses.update', old('_expense_id')) : route('expenses.store') }}" enctype="multipart/form-data" data-create-url="{{ route('expenses.store') }}" data-update-base="{{ url('/gastos') }}" data-default-cash="{{ $filters['petty_cash_account_id'] }}">
        @csrf<input type="hidden" name="_method" value="PUT" @disabled(!old('_expense_id'))><input type="hidden" name="_form" value="expense"><input type="hidden" name="_expense_id" value="{{ old('_expense_id') }}"><input type="hidden" name="version" value="{{ old('version') }}">
        <div class="modal-header"><div><span class="eyebrow">CADA GASTO CUENTA</span><h2 id="expense-dialog-title">{{ old('_expense_id') ? 'Editar gasto' : 'Registrar gasto' }}</h2></div><button type="button" class="close-modal" data-close-dialog aria-label="Cerrar formulario">×</button></div>
        <div class="modal-body stack-form">
            @if($errors->any() && old('_form') === 'expense')<div class="alert error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="form-row"><label>Fecha del gasto<input type="date" name="spent_on" value="{{ old('spent_on', $today->toDateString()) }}" max="{{ $today->toDateString() }}" required></label><label>Importe ({{ config('expenses.currency') }})<input type="number" name="amount" value="{{ old('amount') }}" min="0.01" max="9999999999.99" step="0.01" inputmode="decimal" placeholder="0.00" required></label></div>
            <label>Descripción<textarea name="description" rows="3" maxlength="500" placeholder="¿En qué gastaste?" required>{{ old('description') }}</textarea></label>
            <label>Caja chica<select name="petty_cash_account_id"><option value="">Gasto personal / sin caja chica</option>@foreach($cashAccounts as $account)<option value="{{ $account->id }}" @selected(old('petty_cash_account_id', $filters['petty_cash_account_id']) == $account->id) @disabled(!$account->editable())>{{ $account->code }} · {{ $account->user->name }}{{ !$account->editable() ? ' (bloqueada)' : '' }}</option>@endforeach</select></label>
            <label>Categoría<select name="category" required>@foreach($categories as $category)<option value="{{ $category->name }}" data-inactive="{{ $category->active ? '0' : '1' }}" @selected(old('category', 'Otros') === $category->name) @disabled(!$category->active && old('category') !== $category->name)>{{ $category->name }}{{ !$category->active ? ' (desactivada)' : '' }}</option>@endforeach</select></label>
            <div class="form-row"><label>Proveedor / lugar<input name="provider" value="{{ old('provider') }}" maxlength="200"></label><label>RUC / DNI del proveedor<input name="tax_id" value="{{ old('tax_id') }}" maxlength="20" inputmode="numeric" pattern="[0-9]{8,20}"></label></div>
            <label>Tipo de comprobante<select name="receipt_type_id"><option value="">Sin especificar</option>@foreach($receiptTypes as $type)<option value="{{ $type->id }}" data-inactive="{{ $type->active ? '0' : '1' }}" @selected(old('receipt_type_id') == $type->id) @disabled(!$type->active && old('receipt_type_id') != $type->id)>{{ $type->name }}{{ !$type->active ? ' (desactivado)' : '' }}</option>@endforeach</select></label>
            <div class="form-row"><label>Serie<input name="series" value="{{ old('series') }}" maxlength="30" placeholder="B001"></label><label>Número de comprobante<input name="document_number" value="{{ old('document_number') }}" maxlength="50" placeholder="000123"></label></div>
            <label>Observación<textarea name="observation" rows="2" maxlength="500">{{ old('observation') }}</textarea></label>
            <label class="upload-field">Comprobante <span class="optional">· opcional</span><input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png"><small>Boleta, factura o recibo · PDF, JPG o PNG · hasta 5 MB</small><small id="current-receipt"></small></label>
            <p class="form-note">Tu comprobante es privado. Las modificaciones quedan registradas en el historial.</p>
        </div>
        <div class="modal-footer"><button type="button" class="btn secondary" data-close-dialog>Cancelar</button><button class="btn primary" type="submit">Guardar gasto</button></div>
    </form>
</dialog>
@endif
@endpush
