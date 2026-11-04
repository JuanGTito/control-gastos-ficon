<?php

namespace App\Modules\PettyCash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CashReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['figures' => 'array', 'cash_version' => 'integer'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
