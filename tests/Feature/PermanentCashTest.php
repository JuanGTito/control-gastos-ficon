<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Expenses\Actions\SaveExpense;
use App\Modules\PettyCash\Models\PettyCashAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PermanentCashTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $role = 'technician'): User
    {
        return User::factory()->create(['username' => fake()->unique()->userName(), 'role' => $role, 'active' => true]);
    }

    private function cash(User $owner, User $admin): PettyCashAccount
    {
        $this->actingAs($admin)->post('/caja-chica', ['user_id' => $owner->id, 'purpose' => 'Fondo permanente de trabajo', 'received_on' => now(config('expenses.timezone'))->toDateString(), 'received_amount' => '100'])->assertRedirect();

        return PettyCashAccount::where('user_id', $owner->id)->where('is_permanent', true)->firstOrFail();
    }

    private function spend(User $actor, PettyCashAccount $cash, string $amount, bool $receipt = false)
    {
        return app(SaveExpense::class)->execute($actor, ['spent_on' => now(config('expenses.timezone'))->toDateString(), 'category' => 'Materiales', 'description' => 'Materiales pagados por el responsable', 'amount' => $amount, 'petty_cash_account_id' => $cash->id], $receipt ? UploadedFile::fake()->image('boleta.png') : null);
    }

    private function funding(string $amount = '100'): array
    {
        return ['amount' => $amount, 'funded_on' => now(config('expenses.timezone'))->toDateString(), 'request_key' => (string) Str::uuid(), 'note' => 'Reposición del fondo'];
    }

    public function test_negative_fifteen_is_reimbursed_first_leaving_eighty_five(): void
    {
        $admin = $this->person('admin');
        $tech = $this->person();
        $cash = $this->cash($tech, $admin);
        $this->spend($tech, $cash, '115');
        $this->assertSame('15.00', $cash->fresh()->figures()['personal_advance']);
        $this->assertSame('0.00', $cash->figures()['available']);
        $this->actingAs($admin)->post('/caja-chica/'.$cash->id.'/entregas', $this->funding())->assertRedirect();
        $entry = $cash->fundings()->latest('id')->first();
        $this->assertSame('15.00', $entry->reimbursement);
        $this->assertSame('85.00', $entry->available_addition);
        $this->assertSame('-15.00', $entry->opening_balance);
        $this->assertSame('85.00', $entry->closing_balance);
        $figures = $cash->fresh()->figures();
        $this->assertSame('85.00', $figures['available']);
        $this->assertSame('0.00', $figures['personal_advance']);
        $this->assertSame('200.00', $figures['received']);
        $this->assertSame('115.00', $figures['spent']);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('cash_returns', 0);
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertDatabaseHas('audit_entries', ['subject_type' => 'cash', 'action' => 'funded', 'actor_id' => $admin->id]);
    }

    public function test_smaller_delivery_only_partially_reimburses_the_advance(): void
    {
        $admin = $this->person('admin');
        $tech = $this->person();
        $cash = $this->cash($tech, $admin);
        $this->spend($tech, $cash, '115');
        $this->actingAs($admin)->post('/caja-chica/'.$cash->id.'/entregas', $this->funding('5'))->assertRedirect();
        $this->assertSame('10.00', $cash->fresh()->figures()['personal_advance']);
        $this->assertSame('0.00', $cash->figures()['available']);
        $this->assertSame('0.00', $cash->fundings()->latest('id')->first()->available_addition);
    }

    public function test_positive_balance_carries_over_and_duplicate_submissions_do_not_pay_twice(): void
    {
        $admin = $this->person('admin');
        $tech = $this->person();
        $cash = $this->cash($tech, $admin);
        $this->spend($tech, $cash, '40');
        $data = $this->funding();
        $this->actingAs($admin)->post('/caja-chica/'.$cash->id.'/entregas', $data)->assertRedirect();
        $this->post('/caja-chica/'.$cash->id.'/entregas', $data)->assertRedirect();
        $this->assertSame('160.00', $cash->fresh()->figures()['available']);
        $this->assertDatabaseCount('cash_fundings', 2);
        $this->post('/caja-chica/'.$cash->id.'/entregas', array_merge($data, ['amount' => '200']))->assertSessionHasErrors('funding');
        $this->assertSame('200.00', $cash->fresh()->received_amount);
    }

    public function test_expenses_already_settled_by_a_funding_cannot_rewrite_reimbursement_history(): void
    {
        $admin = $this->person('admin');
        $tech = $this->person();
        $cash = $this->cash($tech, $admin);
        $expense = $this->spend($tech, $cash, '115');
        $this->actingAs($admin)->post('/caja-chica/'.$cash->id.'/entregas', $this->funding())->assertRedirect();
        $this->actingAs($tech)->put('/gastos/'.$expense->id, ['spent_on' => now(config('expenses.timezone'))->toDateString(), 'description' => 'No sobrescribir', 'category' => 'Materiales', 'amount' => '40', 'version' => $expense->version])->assertSessionHasErrors('expense');
        $this->assertSame('115.00', $expense->fresh()->amount);
        $this->assertSame('15.00', $cash->fundings()->latest('id')->first()->reimbursement);
        $this->put('/gastos/'.$expense->id, ['spent_on' => $expense->spent_on->toDateString(), 'description' => 'Descripción corregida', 'category' => 'Materiales', 'amount' => '115', 'version' => $expense->version])->assertRedirect();
        $this->assertSame('Descripción corregida', $expense->fresh()->description);
    }

    public function test_admin_can_have_a_cash_but_technician_and_accountant_cannot_deliver_money(): void
    {
        $admin = $this->person('admin');
        $cash = $this->cash($admin, $admin);
        $this->spend($admin, $cash, '115');
        $this->actingAs($admin)->post('/caja-chica/'.$cash->id.'/entregas', $this->funding())->assertRedirect();
        $this->assertSame('85.00', $cash->fresh()->figures()['available']);
        foreach (['technician', 'accountant'] as $role) {
            $this->actingAs($this->person($role))->post('/caja-chica/'.$cash->id.'/entregas', $this->funding())->assertForbidden();
            $this->post('/caja-chica', [])->assertForbidden();
        }
    }

    public function test_one_permanent_fund_per_person_and_initial_delivery_cannot_be_overwritten(): void
    {
        $admin = $this->person('admin');
        $tech = $this->person();
        $cash = $this->cash($tech, $admin);
        $this->post('/caja-chica', ['user_id' => $tech->id, 'purpose' => 'Duplicada', 'received_on' => now()->toDateString(), 'received_amount' => '100'])->assertSessionHasErrors('user_id');
        $this->put('/caja-chica/'.$cash->id, ['purpose' => 'No alterar entregas', 'received_on' => $cash->received_on->toDateString(), 'received_amount' => '500', 'version' => $cash->version])->assertSessionHasErrors('received_amount');
        $this->assertDatabaseCount('petty_cash_accounts', 1);
        $this->assertDatabaseCount('cash_fundings', 1);
        $this->assertSame('100.00', $cash->fresh()->received_amount);
    }

    public function test_accountant_reads_reviews_exports_and_cannot_modify_money_or_expenses(): void
    {
        Storage::fake('local');
        $admin = $this->person('admin');
        $tech = $this->person();
        $cash = $this->cash($tech, $admin);
        $expense = $this->spend($tech, $cash, '115', true);
        $accountant = $this->person('accountant');
        $this->actingAs($accountant)->get('/gastos?user_id='.$tech->id)->assertOk()->assertSee($expense->description)->assertDontSee('data-new-expense', false);
        $this->get('/caja-chica/'.$cash->id)->assertOk()->assertSee('Registrar revisión')->assertDontSee('data-modal-open="funding-dialog"', false);
        $this->get('/gastos/'.$expense->id.'/comprobante')->assertDownload();
        $this->get('/gastos/'.$expense->id.'/historial')->assertOk();
        $this->get('/auditoria')->assertOk();
        $this->get('/usuarios')->assertForbidden();
        $this->get('/configuracion')->assertForbidden();
        $this->post('/gastos', [])->assertForbidden();
        $this->put('/gastos/'.$expense->id, [])->assertForbidden();
        $this->post('/caja-chica/'.$cash->id.'/devoluciones', [])->assertForbidden();
        $this->post('/caja-chica/'.$cash->id.'/revisiones', ['outcome' => 'reviewed', 'version' => $cash->fresh()->version, 'note' => 'Comprobantes revisados'])->assertRedirect();
        $this->assertSame($accountant->id, $cash->reviews()->first()->actor_id);
        $this->assertTrue($cash->fresh()->editable());
        $this->spend($tech, $cash, '1');
        $this->get('/caja-chica/'.$cash->id)->assertSee('movimientos posteriores');
    }

    public function test_accountant_observes_missing_receipts_without_requiring_money_return(): void
    {
        $admin = $this->person('admin');
        $tech = $this->person();
        $cash = $this->cash($tech, $admin);
        $this->spend($tech, $cash, '40');
        $this->actingAs($this->person('accountant'))->post('/caja-chica/'.$cash->id.'/revisiones', ['outcome' => 'reviewed', 'version' => $cash->fresh()->version])->assertSessionHasErrors('cash');
        $this->post('/caja-chica/'.$cash->id.'/revisiones', ['outcome' => 'observed', 'version' => $cash->fresh()->version, 'note' => 'Falta boleta'])->assertRedirect();
        $this->assertSame('60.00', $cash->fresh()->figures()['available']);
        $this->assertTrue($cash->editable());
        $this->actingAs($tech)->post('/caja-chica/'.$cash->id.'/estado', ['status' => 'submitted', 'version' => $cash->fresh()->version])->assertSessionHasErrors('cash');
    }

    public function test_admin_provisions_technicians_and_accountants_without_promoting_itself(): void
    {
        $admin = $this->person('admin');
        $this->actingAs($admin)->get('/usuarios')->assertOk();
        foreach (['technician', 'accountant'] as $role) {
            $this->post('/usuarios', ['name' => 'Cuenta '.$role, 'username' => $role, 'email' => $role.'@example.test', 'role' => $role, 'active' => '1', 'password' => 'UnaClaveSegura123', 'password_confirmation' => 'UnaClaveSegura123'])->assertRedirect();
        }
        $this->post('/usuarios', ['role' => 'superadmin'])->assertForbidden();
        $this->put('/usuarios/'.$admin->id, ['role' => 'technician'])->assertForbidden();
        $this->assertDatabaseHas('users', ['username' => 'accountant', 'role' => 'accountant']);
    }

    public function test_full_excel_has_funding_allocation_without_a_duplicate_expense(): void
    {
        $admin = $this->person('admin');
        $tech = $this->person();
        $cash = $this->cash($tech, $admin);
        $this->spend($tech, $cash, '115');
        $this->actingAs($admin)->post('/caja-chica/'.$cash->id.'/entregas', $this->funding());
        $this->actingAs($this->person('accountant'));
        $response = $this->get('/caja-chica/'.$cash->id.'/exportar/xlsx')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'cash-permanent-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $this->assertEquals(85, $book->getSheet(0)->getCell('E17')->getCalculatedValue());
            $this->assertSame('SALDO DISPONIBLE', $book->getSheet(0)->getCell('G15')->getCalculatedValue());
            $this->assertEquals(15, $book->getSheetByName('ENTREGAS')->getCell('D5')->getValue());
            $this->assertEquals(85, $book->getSheetByName('ENTREGAS')->getCell('E5')->getValue());
            $this->assertEquals(115, $book->getSheetByName('BASE_RENDICIONES')->getCell('P6')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
            gc_collect_cycles();
        }
        $this->get('/caja-chica/'.$cash->id.'/exportar/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
