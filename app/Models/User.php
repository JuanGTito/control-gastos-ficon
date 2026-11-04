<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function managesExpenses(): bool
    {
        return in_array($this->role, ['admin', 'superadmin'], true);
    }

    public function isSuperadmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function canViewAllExpenses(): bool
    {
        return $this->managesExpenses() || $this->role === 'accountant';
    }

    public function scopeOperational($query)
    {
        return $query->where('role', '!=', 'superadmin');
    }

    public function canReviewCash(): bool
    {
        return $this->canViewAllExpenses();
    }

    public function canManageUsers(): bool
    {
        return $this->managesExpenses();
    }

    public function canRecordExpenses(): bool
    {
        return $this->active && in_array($this->role, ['user', 'technician', 'admin', 'superadmin'], true);
    }

    public function roleLabel(): string
    {
        return ['superadmin' => 'Superadministrador', 'admin' => 'Administrador', 'user' => 'Técnico', 'technician' => 'Técnico', 'accountant' => 'Contador'][$this->role];
    }
}
