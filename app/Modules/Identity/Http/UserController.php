<?php

namespace App\Modules\Identity\Http;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canManageUsers(), 403);

        $roles = ['technician' => 'Técnico', 'accountant' => 'Contador', 'user' => 'Técnico (cuenta anterior)'];
        if ($request->user()->isSuperadmin()) {
            $roles += ['admin' => 'Administrador'];
        }

        return view('identity.users', ['users' => User::operational()->orderBy('name')->paginate(15), 'roles' => $roles]);
    }

    public function store(Request $request, RecordAudit $audit)
    {
        return $this->save($request, $audit);
    }

    public function update(Request $request, User $user, RecordAudit $audit)
    {
        return $this->save($request, $audit, $user);
    }

    private function save(Request $request, RecordAudit $audit, ?User $user = null)
    {
        abort_unless($request->user()->canManageUsers(), 403);
        if (! $request->user()->isSuperadmin()) {
            abort_if($user && in_array($user->role, ['admin', 'superadmin'], true), 403);
            abort_if(! in_array($request->input('role'), ['technician', 'user', 'accountant'], true), 403);
        }
        $request->merge(['username' => mb_strtolower(trim((string) $request->input('username'))), 'email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'regex:/^[a-z0-9._-]{3,50}$/', Rule::unique('users')->ignore($user?->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', Rule::in(['user', 'technician', 'accountant', 'admin', 'superadmin'])],
            'active' => ['required', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(12)->letters()->numbers(), 'max:255'],
        ]);
        DB::transaction(function () use ($request, $audit, $user, $data) {
            // Serializar cambios de privilegios para conservar al menos un superadministrador activo.
            $superadmins = User::where('role', 'superadmin')->orderBy('id')->lockForUpdate()->get();
            $model = $user ? User::lockForUpdate()->findOrFail($user->id) : new User;
            if (! $request->user()->isSuperadmin()) {
                abort_if($user && in_array($model->role, ['admin', 'superadmin'], true), 403);
            }
            if ($model->id === $request->user()->id && ($data['role'] !== 'superadmin' || ! $data['active'])) {
                throw ValidationException::withMessages(['role' => 'No puedes desactivar tu propia cuenta ni quitarte el rol de superadministrador.']);
            }
            if ($model->role === 'superadmin' && $model->active && ($data['role'] !== 'superadmin' || ! $data['active']) && $superadmins->where('active', true)->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Debe existir al menos un superadministrador activo.']);
            }
            $fields = ['name', 'username', 'email', 'role', 'active'];
            $before = $model->exists ? $model->only($fields) : null;
            if (empty($data['password'])) {
                unset($data['password']);
            } else {
                $model->setRememberToken(null);
            }
            $model->fill($data)->save();
            $after = $model->only($fields);
            if (! empty($data['password'])) {
                $after['password_changed'] = true;
            }
            $audit->record($request->user(), 'user', $model->id, $before ? 'updated' : 'created', $before, $after);
            if (isset($data['password']) || ! $model->active) {
                DB::table('sessions')->where('user_id', $model->id)->delete();
            }
        });

        return redirect()->route('users.index')->with('status', 'Usuario guardado correctamente.');
    }
}
