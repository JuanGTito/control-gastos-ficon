<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAudit;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Catalogs\Models\CatalogItem;
use App\Modules\Expenses\Actions\SaveExpense;
use App\Modules\Expenses\Models\Expense;
use App\Modules\PettyCash\Models\PettyCashAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PettyCashTest extends TestCase
{
    use RefreshDatabase;

    private array $books = [];

    protected function tearDown(): void
    {
        foreach ($this->books as $book) {
            $book->disconnectWorksheets();
        }
        $this->books = [];
        parent::tearDown();
        gc_collect_cycles();
    }

    private function person(string $role = 'user'): User
    {
        return User::factory()->create(['username' => fake()->unique()->userName(), 'role' => $role, 'active' => true]);
    }

    private function cash(User $owner, ?User $admin = null, string $amount = '100.00'): PettyCashAccount
    {
        // Representa una rendición histórica anterior a la migración de caja permanente.
        $admin ??= $this->person('admin');
        $this->actingAs($admin);
        $cash = PettyCashAccount::create(['user_id' => $owner->id, 'delivered_by' => $admin->id, 'purpose' => 'Compras de oficina', 'received_on' => now(config('expenses.timezone'))->toDateString(), 'received_amount' => $amount, 'currency' => 'PEN', 'dni' => '00123456', 'area' => 'Operaciones', 'branch_id' => CatalogItem::ofKind('branch')->first()->id, 'is_permanent' => false, 'status' => 'open', 'version' => 1]);
        $cash->update(['code' => 'RC-'.str_pad((string) $cash->id, 6, '0', STR_PAD_LEFT)]);
        app(RecordAudit::class)->record($admin, 'cash', $cash->id, 'created', null, $cash->snapshot());

        return $cash;
    }

    private function expense(User $owner, ?PettyCashAccount $cash = null, array $overrides = [], bool $receipt = false): Expense
    {
        $data = array_merge(['spent_on' => now(config('expenses.timezone'))->toDateString(), 'description' => 'Compra verificada', 'category' => 'Materiales', 'amount' => '40.00', 'petty_cash_account_id' => $cash?->id, 'provider' => 'Proveedor de prueba', 'tax_id' => '20123456789', 'series' => 'B001', 'document_number' => '0000123', 'receipt_type_id' => CatalogItem::where('kind', 'receipt_type')->where('name', 'Boleta')->value('id')], $overrides);

        return app(SaveExpense::class)->execute($owner, $data, $receipt ? UploadedFile::fake()->image('boleta.png') : null);
    }

    private function workbook($response)
    {
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'rendicion-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $this->books[] = $book;

            return $book;
        } finally {
            unlink($path);
        }
    }

    public function test_catalog_permissions_and_case_insensitive_duplicates(): void
    {
        $this->actingAs($this->person())->get('/configuracion')->assertForbidden();
        $this->post('/configuracion', [])->assertForbidden();
        $this->actingAs($this->person('admin'))->get('/configuracion')->assertOk();
        $this->post('/configuracion', ['kind' => 'category', 'name' => 'Viajes de trabajo', 'active' => '1', 'sort_order' => 20])->assertRedirect();
        $this->post('/configuracion', ['kind' => 'category', 'name' => 'VIAJES DE TRABAJO', 'active' => '1', 'sort_order' => 20])->assertSessionHasErrors('name_key');
        $this->assertDatabaseHas('audit_entries', ['subject_type' => 'catalog', 'action' => 'created']);
    }

    public function test_renamed_inactive_category_keeps_history_and_can_be_filtered(): void
    {
        $owner = $this->person();
        $expense = $this->expense($owner);
        $category = CatalogItem::findOrFail($expense->category_id);
        $this->actingAs($this->person('admin'))->put('/configuracion/'.$category->id, ['kind' => 'category', 'name' => 'Materiales de oficina', 'active' => '0', 'sort_order' => 0, 'version' => 1])->assertRedirect();
        $this->assertSame($category->id, $expense->fresh()->category_id);
        $this->actingAs($owner)->get('/gastos?category=Materiales%20de%20oficina')->assertOk()->assertSee('Compra verificada');
        $this->post('/gastos', ['spent_on' => now(config('expenses.timezone'))->toDateString(), 'description' => 'Nuevo', 'amount' => '1', 'category' => 'Materiales de oficina'])->assertSessionHasErrors('category');
        $this->put('/gastos/'.$expense->id, ['spent_on' => $expense->spent_on->toDateString(), 'description' => 'Corregido', 'amount' => '40', 'category' => 'Materiales de oficina', 'version' => 1])->assertRedirect();
        $this->assertSame('Materiales', AuditEntry::where('subject_type', 'expense')->where('action', 'created')->first()->after['category']);
    }

    public function test_only_admin_delivers_money_and_other_users_cannot_access_cash(): void
    {
        $owner = $this->person();
        $this->actingAs($owner)->post('/caja-chica', [])->assertForbidden();
        $cash = $this->cash($owner);
        $this->assertMatchesRegularExpression('/^RC-\d{6}$/', $cash->code);
        $this->actingAs($owner)->get('/caja-chica/'.$cash->id)->assertOk()->assertSee($cash->code);
        $this->actingAs($this->person())->get('/caja-chica/'.$cash->id)->assertForbidden();
        $this->get('/caja-chica/'.$cash->id.'/exportar/xlsx')->assertForbidden();
        $this->get('/gastos?petty_cash_account_id='.$cash->id)->assertForbidden();
        $this->post('/gastos', ['spent_on' => now(config('expenses.timezone'))->toDateString(), 'description' => 'Ajeno', 'category' => 'Otros', 'amount' => '1', 'petty_cash_account_id' => $cash->id])->assertForbidden();
    }

    public function test_cash_lifecycle_balances_money_receipts_and_records_review(): void
    {
        Storage::fake('local');
        $owner = $this->person();
        $admin = $this->person('admin');
        $cash = $this->cash($owner, $admin);
        $this->expense($owner, $cash, [], true);
        $this->actingAs($owner)->post('/caja-chica/'.$cash->id.'/devoluciones', ['amount' => '60', 'returned_on' => now(config('expenses.timezone'))->toDateString(), 'note' => 'Saldo devuelto'])->assertRedirect();
        $this->assertSame('0.00', $cash->fresh()->figures()['difference']);
        $this->assertSame('40.00', $cash->figures()['spent']);
        $this->assertSame('60.00', $cash->figures()['returned']);
        $this->post('/caja-chica/'.$cash->id.'/estado', ['status' => 'submitted', 'version' => $cash->fresh()->version])->assertRedirect();
        $this->assertSame('submitted', $cash->fresh()->status);
        $this->post('/caja-chica/'.$cash->id.'/estado', ['status' => 'closed', 'version' => $cash->version])->assertForbidden();
        $this->actingAs($admin)->post('/caja-chica/'.$cash->id.'/estado', ['status' => 'closed', 'version' => $cash->fresh()->version])->assertRedirect();
        $this->assertSame('closed', $cash->fresh()->status);
        $this->assertSame($admin->id, $cash->fresh()->reviewed_by);
        $this->assertDatabaseHas('audit_entries', ['subject_type' => 'cash', 'subject_id' => $cash->id, 'action' => 'returned', 'actor_id' => $owner->id]);
        $this->get('/caja-chica/'.$cash->id)->assertOk()->assertSee('Cerrada');
    }

    public function test_close_rejects_missing_receipts_even_when_money_balances(): void
    {
        $owner = $this->person();
        $cash = $this->cash($owner, amount: '40');
        $this->expense($owner, $cash);
        $this->actingAs($owner)->post('/caja-chica/'.$cash->id.'/estado', ['status' => 'submitted', 'version' => $cash->fresh()->version])->assertRedirect();
        $this->actingAs($this->person('admin'))->post('/caja-chica/'.$cash->id.'/estado', ['status' => 'closed', 'version' => $cash->fresh()->version])->assertSessionHasErrors('cash');
        $this->assertSame('submitted', $cash->fresh()->status);
    }

    public function test_presented_cash_blocks_expense_changes_and_returns_until_observed(): void
    {
        $owner = $this->person();
        $cash = $this->cash($owner);
        $expense = $this->expense($owner, $cash);
        $this->actingAs($owner)->post('/caja-chica/'.$cash->id.'/estado', ['status' => 'submitted', 'version' => $cash->fresh()->version]);
        $this->put('/gastos/'.$expense->id, ['spent_on' => now(config('expenses.timezone'))->toDateString(), 'description' => 'Cambio bloqueado', 'amount' => '20', 'category' => 'Materiales', 'version' => 1])->assertSessionHasErrors('petty_cash_account_id');
        $this->post('/caja-chica/'.$cash->id.'/devoluciones', ['amount' => '1', 'returned_on' => now(config('expenses.timezone'))->toDateString()])->assertSessionHasErrors('cash');
        $this->actingAs($this->person('admin'))->post('/caja-chica/'.$cash->id.'/estado', ['status' => 'observed', 'version' => $cash->fresh()->version])->assertSessionHasErrors('review_note');
        $this->post('/caja-chica/'.$cash->id.'/estado', ['status' => 'observed', 'version' => $cash->fresh()->version, 'review_note' => 'Adjuntar boleta'])->assertRedirect();
        $this->assertTrue($cash->fresh()->editable());
    }

    public function test_returns_cannot_exceed_available_money_and_dont_become_expenses(): void
    {
        $owner = $this->person();
        $cash = $this->cash($owner);
        $this->expense($owner, $cash);
        $this->actingAs($owner)->post('/caja-chica/'.$cash->id.'/devoluciones', ['amount' => '60.01', 'returned_on' => now(config('expenses.timezone'))->toDateString()])->assertSessionHasErrors('amount');
        $this->post('/caja-chica/'.$cash->id.'/devoluciones', ['amount' => '60', 'returned_on' => now(config('expenses.timezone'))->toDateString()])->assertRedirect();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('cash_returns', 1);
    }

    public function test_cash_owner_is_distinct_from_admin_actor_and_cannot_be_reassigned(): void
    {
        $owner = $this->person();
        $admin = $this->person('admin');
        $cash = $this->cash($owner, $admin);
        $expense = $this->expense($admin, $cash);
        $this->assertSame($owner->id, $expense->user_id);
        $this->assertSame($admin->id, AuditEntry::where('subject_type', 'expense')->first()->actor_id);
        $otherCash = $this->cash($this->person(), $admin);
        $this->actingAs($admin)->put('/gastos/'.$expense->id, ['spent_on' => now(config('expenses.timezone'))->toDateString(), 'description' => 'No reasignar', 'category' => 'Materiales', 'amount' => '1', 'version' => 1, 'petty_cash_account_id' => $otherCash->id])->assertSessionHasErrors('petty_cash_account_id');
        $this->assertSame($cash->id, $expense->fresh()->petty_cash_account_id);
    }

    public function test_filters_by_person_never_bypass_permissions(): void
    {
        $owner = $this->person();
        $other = $this->person();
        $this->expense($owner, overrides: ['description' => 'Mío']);
        $this->expense($other, overrides: ['description' => 'De otra persona']);
        $this->actingAs($owner)->get('/reportes/xlsx?user_id='.$other->id)->assertForbidden();
        $this->get('/caja-chica?user_id='.$other->id)->assertForbidden();
        $this->actingAs($this->person('admin'))->get('/gastos?user_id='.$owner->id)->assertOk()->assertSee('Mío')->assertDontSee('De otra persona');
    }

    public function test_full_template_contains_real_data_formulas_and_no_example_rows(): void
    {
        $owner = $this->person();
        $cash = $this->cash($owner);
        $this->expense($owner, $cash);
        $book = $this->workbook($this->actingAs($owner)->get('/caja-chica/'.$cash->id.'/exportar/xlsx'));
        $sheet = $book->getSheet(0);
        $this->assertSame($owner->name, $sheet->getCell('C7')->getValue());
        $this->assertSame('00123456', $sheet->getCell('C8')->getValue());
        $this->assertSame('00123456', $sheet->getCell('C8')->getFormattedValue());
        $this->assertSame('0000123', $sheet->getCell('I21')->getValue());
        $this->assertSame('0000123', $sheet->getCell('I21')->getFormattedValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('I21')->getDataType());
        $this->assertEquals(100, $sheet->getCell('E13')->getValue());
        $this->assertEquals(40, $sheet->getCell('J41')->getCalculatedValue());
        $this->assertEquals(60, $sheet->getCell('E17')->getCalculatedValue());
        $this->assertEquals(1, $sheet->getCell('E44')->getCalculatedValue());
        $base = $book->getSheetByName('BASE_RENDICIONES');
        $this->assertSame('Compra verificada', $base->getCell('K6')->getValue());
        $this->assertEmpty($base->getCell('K7')->getValue());
        $this->assertStringNotContainsString('Juan Pérez', json_encode($sheet->toArray()));
        $this->assertSame('A1:L58', $sheet->getPageSetup()->getPrintArea());
        $this->get('/caja-chica/'.$cash->id.'/exportar/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_filtered_template_does_not_present_a_false_cash_reconciliation(): void
    {
        $owner = $this->person();
        $cash = $this->cash($owner);
        $this->expense($owner, $cash, ['category' => 'Transporte', 'amount' => '10']);
        $this->expense($owner, $cash, ['category' => 'Materiales', 'amount' => '20']);
        $book = $this->workbook($this->actingAs($owner)->get('/reportes/xlsx?category=Transporte&petty_cash_account_id='.$cash->id));
        $sheet = $book->getSheet(0);
        $this->assertSame('CONSULTA FILTRADA', $sheet->getCell('G15')->getValue());
        $this->assertEmpty($sheet->getCell('E13')->getValue());
        $this->assertSame('', $sheet->getCell('E17')->getCalculatedValue());
        $this->assertEquals(10, $sheet->getCell('J41')->getCalculatedValue());
    }

    public function test_template_extends_past_twenty_rows_and_configuration_past_thirty(): void
    {
        $owner = $this->person();
        $cash = $this->cash($owner);
        for ($i = 0; $i < 23; $i++) {
            $this->expense($owner, $cash, ['description' => 'Gasto '.$i, 'amount' => '0.10']);
        }
        for ($i = 0; $i < 35; $i++) {
            CatalogItem::create(['kind' => 'category', 'name' => 'Adicional '.$i, 'name_key' => 'adicional '.$i, 'active' => true]);
        }
        $book = $this->workbook($this->actingAs($owner)->get('/caja-chica/'.$cash->id.'/exportar/xlsx'));
        $sheet = $book->getSheet(0);
        $this->assertSame('Gasto 22', $sheet->getCell('E43')->getValue());
        $this->assertEquals(2.30, $sheet->getCell('J44')->getCalculatedValue());
        $this->assertEquals(23, $sheet->getCell('E47')->getCalculatedValue());
        $this->assertEquals(23, $sheet->getCell('E50')->getCalculatedValue());
        $this->assertSame('A1:L61', $sheet->getPageSetup()->getPrintArea());
        $this->assertStringContainsString('$A$'.(CatalogItem::where('kind', 'category')->count() + 4), $book->getNamedRange('Lista_Categorias')->getRange());
    }

    public function test_multiple_cash_and_personal_groups_export_separately_without_duplicates(): void
    {
        $owner = $this->person();
        $admin = $this->person('admin');
        $cashOne = $this->cash($owner, $admin);
        $cashTwo = $this->cash($owner, $admin);
        $this->expense($owner, $cashOne);
        $this->expense($owner, $cashTwo);
        $this->expense($owner);
        $book = $this->workbook($this->actingAs($owner)->get('/reportes/xlsx'));
        $this->assertSame(5, $book->getSheetCount());
        $base = $book->getSheetByName('BASE_RENDICIONES');
        $this->assertSame('Compra verificada', $base->getCell('K8')->getValue());
        $this->assertEmpty($base->getCell('K9')->getValue());
        $this->assertEquals(120, array_sum(array_map(fn ($row) => $row[0], $base->rangeToArray('P6:P8'))));
    }

    public function test_stale_cash_versions_and_invalid_delivery_dates_are_rejected(): void
    {
        $owner = $this->person();
        $cash = $this->cash($owner);
        $this->expense($owner, $cash);
        $this->actingAs($owner)->post('/caja-chica/'.$cash->id.'/estado', ['status' => 'submitted', 'version' => 1])->assertSessionHasErrors('cash');
        $this->post('/gastos', ['spent_on' => now(config('expenses.timezone'))->subDay()->toDateString(), 'description' => 'Anterior', 'amount' => '1', 'category' => 'Otros', 'petty_cash_account_id' => $cash->id])->assertSessionHasErrors('spent_on');
        $this->assertSame('open', $cash->fresh()->status);
    }
}
