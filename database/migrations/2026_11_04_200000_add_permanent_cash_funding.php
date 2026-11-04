<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_accounts', function (Blueprint $table) {
            $table->boolean('is_permanent')->default(false);
            $table->decimal('received_amount', 16, 2)->change();
        });
        // Las rendiciones anteriores permanecen como historial, sin mezclar sus gastos.
        // MySQL/MariaDB permiten múltiples NULL en un índice único, conservando cajas históricas.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE petty_cash_accounts ADD COLUMN permanent_owner_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN is_permanent THEN user_id ELSE NULL END) STORED');
            DB::statement('CREATE UNIQUE INDEX one_permanent_cash_per_user ON petty_cash_accounts (permanent_owner_id)');
        } else {
            DB::statement('CREATE UNIQUE INDEX one_permanent_cash_per_user ON petty_cash_accounts (user_id) WHERE is_permanent = true');
        }
        Schema::create('cash_fundings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->date('funded_on');
            $table->decimal('amount', 12, 2);
            $table->decimal('reimbursement', 12, 2);
            $table->decimal('available_addition', 12, 2);
            $table->decimal('opening_balance', 16, 2);
            $table->decimal('closing_balance', 16, 2);
            $table->uuid('request_key')->unique();
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
        Schema::create('cash_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('outcome', 20);
            $table->text('note')->nullable();
            $table->unsignedInteger('cash_version');
            $table->json('figures');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_reviews');
        Schema::dropIfExists('cash_fundings');
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX one_permanent_cash_per_user ON petty_cash_accounts');
            Schema::table('petty_cash_accounts', fn (Blueprint $table) => $table->dropColumn('permanent_owner_id'));
        } else {
            DB::statement('DROP INDEX one_permanent_cash_per_user');
        }
        Schema::table('petty_cash_accounts', fn (Blueprint $table) => $table->dropColumn('is_permanent'));
    }
};
