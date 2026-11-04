<?php

namespace App\Modules\Catalogs\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    public const KINDS = ['category' => 'Categorías', 'receipt_type' => 'Tipos de comprobante', 'branch' => 'Sucursales'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind)->orderBy('sort_order')->orderBy('name');
    }
}
