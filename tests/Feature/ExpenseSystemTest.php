<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAudit;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Expenses\Actions\SaveExpense;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Reports\Services\RenditionWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Tests\TestCase;

class ExpenseSystemTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $role = 'user'): User
    {
        return User::factory()->create(['username' => fake()->unique()->userName(), 'role' => $role, 'active' => true]);
    }

    private function data(array $overrides = []): array
    {
        return array_merge(['spent_on' => now(config('expenses.timezone'))->toDateString(), 'description' => 'Almuerzo de trabajo', 'category' => 'Alimentación', 'amount' => '24.90'], $overrides);
    }

    private function expense(User $owner, array $overrides = []): Expense
    {
        return app(SaveExpense::class)->execute($owner, $this->data($overrides), null);
    }

    public function test_guests_cannot_access_any_protected_module(): void
    {
        foreach (['/gastos', '/usuarios', '/auditoria', '/reportes/xlsx', '/reportes/pdf'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_login_accepts_username_and_logout_ends_access(): void
    {
        $user = $this->person();
        $user->update(['username' => 'ana', 'password' => 'UnaClaveSegura123']);
        $this->post('/login', ['login' => 'ana', 'password' => 'UnaClaveSegura123'])->assertRedirect('/gastos');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_rejects_invalid_credentials_and_inactive_accounts(): void
    {
        $user = $this->person();
        $user->update(['active' => false, 'password' => 'UnaClaveSegura123']);
        $this->post('/login', ['login' => $user->username, 'password' => 'UnaClaveSegura123'])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => $user->email, 'password' => 'incorrecta'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_email_login_is_supported(): void
    {
        $user = $this->person();
        $user->update(['email' => 'persona@example.test', 'password' => 'UnaClaveSegura123']);
        $this->post('/login', ['login' => 'PERSONA@EXAMPLE.TEST', 'password' => 'UnaClaveSegura123'])->assertRedirect('/gastos');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/login', ['login' => 'limited-user', 'password' => 'incorrecta']);
        }
        $response->assertSessionHasErrors('login');
        $this->assertStringContainsString('Demasiados intentos.', session('errors')->first('login'));
    }

    public function test_disabled_user_loses_existing_access(): void
    {
        $this->actingAs($this->person()->fill(['active' => false]))->get('/gastos')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_create_expense_saves_exact_amount_owner_and_audit(): void
    {
        $user = $this->person();
        $this->actingAs($user)->post('/gastos', $this->data(['user_id' => 999, 'currency' => 'XXX']))->assertRedirect('/gastos');
        $expense = Expense::firstOrFail();
        $this->assertSame('24.90', $expense->amount);
        $this->assertSame($user->id, $expense->user_id);
        $this->assertSame(config('expenses.currency'), $expense->currency);
        $audit = AuditEntry::firstOrFail();
        $this->assertSame($user->id, $audit->actor_id);
        $this->assertSame('created', $audit->action);
        $this->assertSame('24.90', $audit->after['amount']);
    }

    public function test_edit_records_actor_before_and_after_values(): void
    {
        $owner = $this->person();
        $admin = $this->person('admin');
        $expense = $this->expense($owner);
        $this->actingAs($admin)->put('/gastos/'.$expense->id, $this->data(['amount' => '31.25', 'version' => 1]))->assertRedirect('/gastos');
        $audit = AuditEntry::latest('id')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('24.90', $audit->before['amount']);
        $this->assertSame('31.25', $audit->after['amount']);
        $this->assertSame($owner->id, $expense->fresh()->user_id);
    }

    public function test_stale_edit_does_not_overwrite_newer_values(): void
    {
        $user = $this->person();
        $expense = $this->expense($user);
        $this->actingAs($user)->put('/gastos/'.$expense->id, $this->data(['amount' => '40', 'version' => 1]))->assertRedirect();
        $this->put('/gastos/'.$expense->id, $this->data(['amount' => '50', 'version' => 1]))->assertSessionHasErrors('version');
        $this->assertSame('40.00', $expense->fresh()->amount);
        $this->assertSame(2, AuditEntry::count());
    }

    public function test_user_cannot_edit_read_receipt_or_history_of_another_owner(): void
    {
        $expense = $this->expense($this->person(), ['description' => 'Gasto privado ajeno']);
        $this->actingAs($this->person());
        $this->get('/gastos')->assertOk()->assertDontSee('Gasto privado ajeno');
        $this->put('/gastos/'.$expense->id, $this->data(['version' => 1]))->assertForbidden();
        $this->get('/gastos/'.$expense->id.'/comprobante')->assertForbidden();
        $this->get('/gastos/'.$expense->id.'/historial')->assertForbidden();
    }

    public function test_admin_can_see_all_expenses_and_manage_operational_users(): void
    {
        $this->expense($this->person(), ['description' => 'Gasto del equipo']);
        $this->actingAs($this->person('admin'))->get('/gastos')->assertOk()->assertSee('Gasto del equipo');
        $this->get('/usuarios')->assertOk();
        $this->get('/auditoria')->assertForbidden();
        $this->post('/usuarios', [])->assertForbidden();
    }

    public function test_date_filters_are_inclusive_and_totals_use_exact_decimals(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(16, 0));
        $user = $this->person();
        $this->expense($user, ['spent_on' => '2026-11-01', 'description' => 'Fuera del rango', 'amount' => '100']);
        $this->expense($user, ['spent_on' => '2026-11-02', 'description' => 'Límite inicial', 'amount' => '0.10']);
        $this->expense($user, ['spent_on' => '2026-11-04', 'description' => 'Límite final', 'amount' => '0.20']);
        $response = $this->actingAs($user)->get('/gastos?from=2026-11-02&to=2026-11-04');
        $response->assertOk()->assertSee('Límite inicial')->assertSee('Límite final')->assertDontSee('Fuera del rango');
        $this->assertEquals('0.30', $response->viewData('totals')['filtered']);
        $this->assertEquals('0.20', $response->viewData('totals')['today']);
        $this->assertEquals('0.30', $response->viewData('totals')['week']);
    }

    public function test_search_and_category_filters_apply_together(): void
    {
        $user = $this->person();
        $this->expense($user, ['description' => 'Taxi al trabajo', 'category' => 'Transporte']);
        $this->expense($user, ['description' => 'Taxi equivocado', 'category' => 'Otros']);
        $this->actingAs($user)->get('/gastos?q=TAXI&category=Transporte')->assertOk()->assertSee('Taxi al trabajo')->assertDontSee('Taxi equivocado');
    }

    public function test_single_ended_date_filters_and_pagination_work(): void
    {
        $user = $this->person();
        for ($i = 0; $i < 13; $i++) {
            $this->expense($user, ['description' => 'Gasto paginado '.$i]);
        }
        $this->actingAs($user)->get('/gastos?to='.now(config('expenses.timezone'))->toDateString())->assertOk()->assertSee('Siguiente');
        $this->get('/gastos?from=2000-01-01&page=2')->assertOk()->assertSee('Gasto paginado 0')->assertDontSee('Gasto paginado 12');
    }

    public function test_invalid_dates_and_amounts_are_rejected(): void
    {
        $this->actingAs($this->person());
        foreach (['-1', '0', '1.001', '10000000000', 'texto'] as $amount) {
            $this->post('/gastos', $this->data(['amount' => $amount]))->assertSessionHasErrors('amount');
        }
        $this->post('/gastos', $this->data(['spent_on' => now()->addDays(2)->toDateString()]))->assertSessionHasErrors('spent_on');
        $this->get('/gastos?from=2026-11-04&to=2026-11-01')->assertSessionHasErrors('to');
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_receipt_is_private_validated_and_downloadable(): void
    {
        Storage::fake('local');
        $user = $this->person();
        $this->actingAs($user)->post('/gastos', $this->data(['receipt' => UploadedFile::fake()->image('boleta.png')]))->assertRedirect();
        $expense = Expense::firstOrFail();
        Storage::disk('local')->assertExists($expense->receipt_path);
        $this->get('/gastos/'.$expense->id.'/comprobante')->assertDownload('boleta.png');
        $this->post('/gastos', $this->data(['receipt' => UploadedFile::fake()->create('script.php', 1, 'text/x-php')]))->assertSessionHasErrors('receipt');
        $this->post('/gastos', $this->data(['receipt' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')]))->assertSessionHasErrors('receipt');
    }

    public function test_failed_audit_rolls_back_expense_and_removes_new_receipt(): void
    {
        Storage::fake('local');
        $this->mock(RecordAudit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->person())->post('/gastos', $this->data(['receipt' => UploadedFile::fake()->image('boleta.png')]));
            $this->fail('Expected audit exception');
        } catch (RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_xlsx_uses_filters_permissions_and_literal_user_text(): void
    {
        $user = $this->person();
        $this->expense($user, ['description' => '=1+1', 'category' => 'Otros', 'amount' => '10.25']);
        $this->expense($user, ['description' => 'No exportar', 'category' => 'Salud']);
        $this->expense($this->person(), ['description' => 'Gasto ajeno', 'category' => 'Otros']);
        $response = $this->actingAs($user)->get('/reportes/xlsx?category=Otros');
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $temp = tempnam(sys_get_temp_dir(), 'gastos-xlsx-');
        try {
            file_put_contents($temp, $response->streamedContent());
            $sheet = IOFactory::load($temp)->getActiveSheet();
            $this->assertSame('=1+1', $sheet->getCell('E21')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('E21')->getDataType());
            $this->assertEquals(10.25, $sheet->getCell('J41')->getCalculatedValue());
            $this->assertSame('TOTAL GASTADO', $sheet->getCell('A41')->getValue());
        } finally {
            unlink($temp);
        }
    }

    public function test_pdf_is_a_real_pdf_and_empty_reports_work(): void
    {
        $user = $this->person();
        $response = $this->actingAs($user)->get('/reportes/pdf');
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->expense($user, ['description' => '<script>alert(1)</script>']);
        $this->get('/reportes/pdf')->assertOk();
    }

    public function test_missing_excel_template_shows_an_actionable_error_instead_of_server_error(): void
    {
        config(['expenses.template_path' => storage_path('app/templates/__plantilla_faltante_de_prueba__.xlsx')]);
        $user = $this->person();

        $this->actingAs($user)->from('/gastos')->get('/reportes/xlsx')->assertRedirect('/gastos')->assertSessionHasErrors('export');
        $this->assertStringContainsString('La exportación Excel no está disponible porque falta la plantilla de rendición o no se puede leer.', session('errors')->first('export'));
    }

    public function test_personal_expenses_export_without_a_cash_account(): void
    {
        $user = $this->person();
        $this->expense($user);
        $this->assertDatabaseCount('petty_cash_accounts', 0);

        $book = app(RenditionWorkbook::class)->build(Expense::with(['user', 'categoryItem', 'receiptType', 'cash'])->get(), [], $user);
        try {
            $sheet = $book->getSheet(0);
            $this->assertSame($user->name, $sheet->getCell('C7')->getValue());
            $this->assertStringContainsString('GASTOS SIN CAJA CHICA', $sheet->getCell('C10')->getValue());
            $this->assertSame('GASTOS SIN CAJA', $sheet->getCell('G15')->getValue());
            $this->assertStringContainsString('No hubo entrega de caja chica', $sheet->getCell('A56')->getValue());
            $this->assertEquals(24.90, $sheet->getCell('J21')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
        unset($book, $sheet);
        gc_collect_cycles();

        $pdf = $this->actingAs($user)->get('/reportes/pdf');
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_export_limit_is_enforced(): void
    {
        config(['expenses.export_limit' => 1]);
        $user = $this->person();
        $this->expense($user);
        $this->expense($user);
        $this->actingAs($user)->get('/reportes/xlsx')->assertSessionHasErrors('export');
        $this->get('/reportes/pdf')->assertSessionHasErrors('export');
    }

    public function test_superadmin_creates_and_disables_users_with_sanitized_audit(): void
    {
        $admin = $this->person('superadmin');
        $this->actingAs($admin)->get('/usuarios')->assertOk();
        $this->post('/usuarios', ['name' => 'Nueva Persona', 'username' => 'nueva', 'email' => 'nueva@example.test', 'role' => 'admin', 'active' => '1', 'password' => 'UnaClaveSegura123', 'password_confirmation' => 'UnaClaveSegura123'])->assertRedirect('/usuarios');
        $user = User::where('username', 'nueva')->firstOrFail();
        $this->assertSame('admin', $user->role);
        $audit = AuditEntry::latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('password', $audit->after);
        $this->put('/usuarios/'.$user->id, ['name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'role' => 'user', 'active' => '0'])->assertRedirect('/usuarios');
        $this->assertFalse($user->fresh()->active);
        $this->get('/auditoria')->assertOk()->assertSee('Nueva Persona');
    }

    public function test_superadmin_cannot_disable_or_demote_own_account(): void
    {
        $user = $this->person('superadmin');
        $this->actingAs($user)->put('/usuarios/'.$user->id, ['name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'role' => 'user', 'active' => '0'])->assertSessionHasErrors('role');
        $this->assertSame('superadmin', $user->fresh()->role);
        $this->assertTrue($user->fresh()->active);
    }

    public function test_command_creates_superadmin_and_rejects_duplicates(): void
    {
        $options = ['--username' => 'superadmin', '--email' => 'admin@example.test', '--name' => 'Super Admin'];
        $this->artisan('app:create-super-admin', $options)->expectsQuestion('Contraseña (mínimo 12 caracteres, letras y números)', 'UnaClaveSegura123')->assertSuccessful();
        $this->assertDatabaseHas('users', ['username' => 'superadmin', 'role' => 'superadmin']);
        $this->artisan('app:create-super-admin', $options)->expectsQuestion('Contraseña (mínimo 12 caracteres, letras y números)', 'UnaClaveSegura123')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_local_password_file_generation_is_denied_outside_local(): void
    {
        $this->artisan('app:create-super-admin', ['--generate-local-password' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_audit_records_cannot_be_changed_through_the_model(): void
    {
        $this->expense($this->person());
        $entry = AuditEntry::firstOrFail();
        $this->expectException(\LogicException::class);
        $entry->update(['action' => 'tampered']);
    }

    public function test_expense_descriptions_are_escaped_in_html(): void
    {
        $user = $this->person();
        $this->expense($user, ['description' => '<script>alert("xss")</script>']);
        $this->actingAs($user)->get('/gastos')->assertOk()->assertDontSee('<script>alert("xss")</script>', false)->assertSee('&lt;script&gt;', false);
    }
}
