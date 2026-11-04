<?php

use App\Models\User;
use App\Modules\Expenses\Actions\SaveExpense;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// Destruye exclusivamente la base MySQL aislada de pruebas de navegador.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'control_gastos_e2e') {
    fwrite(STDERR, "Las fixtures solo pueden ejecutarse en control_gastos_e2e.\n");
    exit(1);
}
$data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
Artisan::call('migrate:fresh', ['--force' => true]);
foreach (['superadmin', 'admin', 'user', 'accountant'] as $role) {
    $user = User::create(['name' => 'Prueba '.$role, 'username' => 'prueba_'.$role, 'email' => $role.'@browser.test', 'password' => $data['password'], 'role' => $role, 'active' => true]);
    if (in_array($role, ['admin', 'user'], true)) {
        app(SaveExpense::class)->execute($user, [
            'spent_on' => now(config('expenses.timezone'))->toDateString(), 'description' => 'Gasto privado de '.$role, 'category' => 'Servicios', 'amount' => '15.50',
        ], null);
    }
}
echo "Fixtures creadas en control_gastos_e2e.\n";
