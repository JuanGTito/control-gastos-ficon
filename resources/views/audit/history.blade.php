@extends('layouts.app')
@section('title', 'Auditoría')
@section('content')
<div class="page-heading"><div><span class="eyebrow">CADA CAMBIO TIENE SU HISTORIA</span><h1>{{ $expense ? 'Historial del gasto' : 'Auditoría' }}<span class="heading-dot">.</span></h1><p>{{ $expense ? $expense->description : 'Consulta quién hizo cada cambio y qué información modificó.' }}</p></div><a class="btn secondary" href="{{ route('expenses.index') }}">← Volver a gastos</a></div>
<section class="panel audit-panel">
@forelse($entries as $entry)
<article class="audit-entry"><div class="audit-meta"><span class="audit-dot"></span><div><strong>{{ $entry->actor->name }}</strong> {{ $entry->action === 'created' ? 'registró' : 'modificó' }} {{ $entry->subjectLabel() }} #{{ $entry->subject_id }}<small>{{ $entry->created_at->timezone(config('expenses.timezone'))->format('d/m/Y H:i:s') }} · {{ config('expenses.timezone') }}</small></div><span class="category-tag">{{ $entry->actionLabel() }}</span></div>
    <div class="audit-changes">@foreach($entry->after as $field => $value)@if($field !== 'receipt_path' && (! $entry->before || ($entry->before[$field] ?? null) !== $value))<div class="audit-change"><strong>{{ \App\Modules\Audit\Models\AuditEntry::fieldLabel($field) }}</strong><span>@if($entry->before)<del>{{ is_bool($entry->before[$field] ?? null) ? (($entry->before[$field] ?? false) ? 'Sí' : 'No') : ($entry->before[$field] ?? 'Sin dato') }}</del><span class="change-arrow">→</span>@endif<span>{{ is_bool($value) ? ($value ? 'Sí' : 'No') : ($value ?? 'Sin dato') }}</span></span></div>@endif
@endforeach</div>
</article>
@empty<div class="empty-state"><h3>Aún no hay cambios registrados</h3></div>@endforelse
{{ $entries->links() }}
</section>
@endsection
