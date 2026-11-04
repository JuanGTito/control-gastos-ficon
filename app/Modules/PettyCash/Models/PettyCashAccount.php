<?php

namespace App\Modules\PettyCash\Models;

use App\Models\User;
use App\Modules\Catalogs\Models\CatalogItem;
use App\Modules\Expenses\Models\Expense;
use Illuminate\Database\Eloquent\Model;

class PettyCashAccount extends Model
{
    public const STATUSES = ['open' => 'Abierta', 'submitted' => 'Presentada', 'observed' => 'Observada', 'closed' => 'Cerrada'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'received_amount' => 'decimal:2', 'submitted_at' => 'datetime', 'closed_at' => 'datetime', 'version' => 'integer', 'is_permanent' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deliverer()
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function branch()
    {
        return $this->belongsTo(CatalogItem::class, 'branch_id');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function returns()
    {
        return $this->hasMany(CashReturn::class);
    }

    public function fundings()
    {
        return $this->hasMany(CashFunding::class);
    }

    public function reviews()
    {
        return $this->hasMany(CashReview::class);
    }

    public function scopeVisibleTo($query, User $user)
    {
        return $query->when(! $user->canViewAllExpenses(), fn ($q) => $q->where('user_id', $user->id));
    }

    public function editable(): bool
    {
        return in_array($this->status, ['open', 'observed'], true);
    }

    public function figures(): array
    {
        $spent = $this->expenses()->get(['amount'])->reduce(fn ($sum, $row) => bcadd($sum, $row->amount, 2), '0.00');
        $returned = $this->returns()->get(['amount'])->reduce(fn ($sum, $row) => bcadd($sum, $row->amount, 2), '0.00');
        $received = $this->is_permanent ? $this->fundings()->get(['amount'])->reduce(fn ($sum, $row) => bcadd($sum, $row->amount, 2), '0.00') : $this->received_amount;
        $balance = bcsub($received, $spent, 2);
        $net = bcsub($balance, $returned, 2);

        return ['received' => $received, 'spent' => $spent, 'returned' => $returned, 'balance' => $balance, 'difference' => $net, 'available' => bccomp($net, '0', 2) > 0 ? $net : '0.00', 'personal_advance' => bccomp($net, '0', 2) < 0 ? bcsub('0', $net, 2) : '0.00', 'count' => $this->expenses()->count(), 'missing' => $this->expenses()->whereNull('receipt_path')->count()];
    }

    public function snapshot(): array
    {
        return $this->only(['code', 'user_id', 'delivered_by', 'branch_id', 'dni', 'area', 'purpose', 'received_amount', 'currency', 'status', 'review_note', 'reviewed_by', 'version', 'is_permanent']) + ['received_on' => $this->received_on->format('Y-m-d'), 'submitted_at' => $this->submitted_at?->toIso8601String(), 'closed_at' => $this->closed_at?->toIso8601String()];
    }
}
