<?php

namespace App\Modules\PettyCash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CashReturn extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'returned_on' => 'date'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
