<?php

namespace App\Modules\Expenses\Models;

use App\Models\User;
use App\Modules\Catalogs\Models\CatalogItem;
use App\Modules\PettyCash\Models\PettyCashAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'amount' => 'decimal:2', 'version' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function categoryItem()
    {
        return $this->belongsTo(CatalogItem::class, 'category_id');
    }

    public function receiptType()
    {
        return $this->belongsTo(CatalogItem::class, 'receipt_type_id');
    }

    public function cash()
    {
        return $this->belongsTo(PettyCashAccount::class, 'petty_cash_account_id');
    }

    public function getCategoryLabelAttribute(): string
    {
        return $this->categoryItem?->name ?? $this->category;
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->canViewAllExpenses()) {
            $query->where('user_id', $user->id);
        }
    }

    public function snapshot(): array
    {
        return [
            'spent_on' => $this->spent_on->format('Y-m-d'), 'description' => $this->description,
            'category' => $this->category, 'amount' => $this->amount, 'currency' => $this->currency,
            'receipt_name' => $this->receipt_name, 'receipt_path' => $this->receipt_path,
            'user_id' => $this->user_id, 'version' => $this->version,
            'category_id' => $this->category_id, 'petty_cash_account_id' => $this->petty_cash_account_id,
            'provider' => $this->provider, 'tax_id' => $this->tax_id, 'receipt_type_id' => $this->receipt_type_id,
            'series' => $this->series, 'document_number' => $this->document_number, 'observation' => $this->observation,
        ];
    }
}
