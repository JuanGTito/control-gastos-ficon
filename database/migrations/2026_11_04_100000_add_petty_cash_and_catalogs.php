<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30);
            $table->string('name', 100);
            $table->string('name_key', 100);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['kind', 'name_key']);
        });
        $lists = [
            'category' => array_unique(array_merge(['Materiales', 'Repuestos', 'Combustible', 'Transporte', 'Alimentación', 'Mantenimiento', 'Instalación', 'Herramientas', 'Servicios básicos', 'Gastos administrativos', 'Gastos de venta', 'Otros'], config('expenses.categories'), DB::table('expenses')->distinct()->pluck('category')->all())),
            'receipt_type' => ['Factura', 'Boleta', 'Recibo', 'Recibo por honorarios', 'Ticket', 'Sin comprobante', 'Nota de venta', 'Otro'],
            'branch' => ['Principal - Juliaca', 'Sucursal Lampa', 'Sucursal Puno'],
        ];
        foreach ($lists as $kind => $names) {
            foreach (array_values($names) as $position => $name) {
                DB::table('catalog_items')->insert(['kind' => $kind, 'name' => $name, 'name_key' => mb_strtolower($name), 'sort_order' => $position, 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        Schema::create('petty_cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivered_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('catalog_items')->restrictOnDelete();
            $table->string('dni', 20)->nullable();
            $table->string('area', 120)->nullable();
            $table->string('purpose', 500);
            $table->date('received_on');
            $table->decimal('received_amount', 12, 2);
            $table->string('currency', 3);
            $table->string('status', 20)->default('open');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('cash_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->date('returned_on');
            $table->decimal('amount', 12, 2);
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('petty_cash_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('catalog_items')->restrictOnDelete();
            $table->foreignId('receipt_type_id')->nullable()->constrained('catalog_items')->restrictOnDelete();
            $table->string('provider', 200)->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->string('series', 30)->nullable();
            $table->string('document_number', 50)->nullable();
            $table->string('observation', 500)->nullable();
            $table->string('category', 100)->change();
        });
        foreach (DB::table('catalog_items')->where('kind', 'category')->get() as $item) {
            DB::table('expenses')->where('category', $item->name)->update(['category_id' => $item->id]);
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('petty_cash_account_id');
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('receipt_type_id');
            $table->dropColumn(['provider', 'tax_id', 'series', 'document_number', 'observation']);
        });
        Schema::dropIfExists('cash_returns');
        Schema::dropIfExists('petty_cash_accounts');
        Schema::dropIfExists('catalog_items');
    }
};
