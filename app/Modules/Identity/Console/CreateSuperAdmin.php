<?php

namespace App\Modules\Identity\Console;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdmin extends Command
{
    protected $signature = 'app:create-super-admin {--username=} {--email=} {--name=} {--generate-local-password : Genera una contraseña y la guarda en .runtime/local-access.txt solo en entorno local}';

    protected $description = 'Crea un superadministrador sin contraseña predeterminada';

    public function handle(RecordAudit $audit): int
    {
        if ($this->option('generate-local-password') && ! app()->environment('local')) {
            $this->error('La generación de un archivo de acceso está permitida solo en local.');

            return self::FAILURE;
        }
        $data = [
            'name' => $this->option('name') ?: $this->ask('Nombre'),
            'username' => Str::lower($this->option('username') ?: $this->ask('Usuario')),
            'email' => Str::lower($this->option('email') ?: $this->ask('Correo electrónico')),
            'password' => $this->option('generate-local-password') ? Str::random(24).'9a' : $this->secret('Contraseña (mínimo 12 caracteres, letras y números)'),
        ];
        $validator = Validator::make($data, [
            'name' => 'required|string|max:120',
            'username' => ['required', 'regex:/^[a-z0-9._-]{3,50}$/', 'unique:users'],
            'email' => 'required|email|max:255|unique:users',
            'password' => ['required', Password::min(12)->letters()->numbers(), 'max:255'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($data, $audit) {
            $user = User::create($data + ['role' => 'superadmin', 'active' => true]);
            $audit->record($user, 'user', $user->id, 'created', null, $user->only(['name', 'username', 'email', 'role', 'active']));
        });
        if ($this->option('generate-local-password')) {
            if (! is_dir(base_path('.runtime'))) {
                mkdir(base_path('.runtime'), 0700, true);
            }
            file_put_contents(base_path('.runtime/local-access.txt'), 'Acceso local: '.config('app.url')."\nUsuario: ".$data['username']."\nContraseña: ".$data['password']."\n");
            $this->info('Credenciales locales guardadas en .runtime/local-access.txt (excluido de Git).');
        }
        $this->info('Superadministrador creado.');

        return self::SUCCESS;
    }
}
