<?php

namespace App\Modules\PettyCash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CashFunding extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['funded_on' => 'date', 'amount' => 'decimal:2', 'reimbursement' => 'decimal:2', 'available_addition' => 'decimal:2', 'opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
