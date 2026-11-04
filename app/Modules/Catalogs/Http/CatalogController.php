<?php

namespace App\Modules\Catalogs\Http;

use App\Modules\Audit\Actions\RecordAudit;
use App\Modules\Catalogs\Models\CatalogItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController
{
    public function index(Request $request)
    {
        abort_unless($request->user()->managesExpenses(), 403);
        $kind = $request->validate(['kind' => ['nullable', Rule::in(array_keys(CatalogItem::KINDS))]])['kind'] ?? 'category';

        return view('catalogs.index', ['kind' => $kind, 'items' => CatalogItem::ofKind($kind)->get()]);
    }

    public function store(Request $request, RecordAudit $audit)
    {
        return $this->save($request, $audit);
    }

    public function update(Request $request, CatalogItem $item, RecordAudit $audit)
    {
        return $this->save($request, $audit, $item);
    }

    private function save(Request $request, RecordAudit $audit, ?CatalogItem $item = null)
    {
        abort_unless($request->user()->managesExpenses(), 403);
        $request->merge(['name' => trim((string) $request->input('name')), 'name_key' => mb_strtolower(trim((string) $request->input('name')))]);
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(CatalogItem::KINDS))],
            'name' => ['required', 'string', 'max:100'],
            'name_key' => [Rule::unique('catalog_items')->where('kind', $request->input('kind'))->ignore($item?->id)],
            'active' => ['required', 'boolean'], 'sort_order' => ['required', 'integer', 'min:0', 'max:99999'],
            'version' => [$item ? 'required' : 'nullable', 'integer'],
        ]);
        DB::transaction(function () use ($request, $data, $item, $audit) {
            $model = $item ? CatalogItem::lockForUpdate()->findOrFail($item->id) : new CatalogItem;
            if ($item && ($model->version !== (int) $data['version'] || $model->kind !== $data['kind'])) {
                throw ValidationException::withMessages(['version' => 'El catálogo cambió. Recarga la página antes de editar.']);
            }
            $before = $item ? $model->only(['kind', 'name', 'active', 'sort_order', 'version']) : null;
            $model->fill(collect($data)->except('version')->all());
            $model->version = $item ? $model->version + 1 : 1;
            $model->save();
            $audit->record($request->user(), 'catalog', $model->id, $item ? 'updated' : 'created', $before, $model->only(['kind', 'name', 'active', 'sort_order', 'version']));
        });

        return redirect()->route('catalogs.index', ['kind' => $data['kind']])->with('status', 'Catálogo guardado. Los movimientos históricos se conservan.');
    }
}
