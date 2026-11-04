<?php

use App\Modules\Audit\Http\AuditController;
use App\Modules\Catalogs\Http\CatalogController;
use App\Modules\Expenses\Http\ExpenseController;
use App\Modules\Identity\Http\AuthController;
use App\Modules\Identity\Http\EnsureActive;
use App\Modules\Identity\Http\UserController;
use App\Modules\PettyCash\Http\PettyCashController;
use App\Modules\Reports\Http\ExportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/gastos');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});
Route::middleware(['auth', EnsureActive::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/configuracion', [CatalogController::class, 'index'])->name('catalogs.index');
    Route::post('/configuracion', [CatalogController::class, 'store'])->name('catalogs.store');
    Route::put('/configuracion/{item}', [CatalogController::class, 'update'])->name('catalogs.update');
    Route::get('/caja-chica', [PettyCashController::class, 'index'])->name('cash.index');
    Route::post('/caja-chica', [PettyCashController::class, 'store'])->name('cash.store');
    Route::post('/caja-chica/{cash}/entregas', [PettyCashController::class, 'fund'])->name('cash.fund');
    Route::post('/caja-chica/{cash}/revisiones', [PettyCashController::class, 'review'])->name('cash.review');
    Route::get('/caja-chica/{cash}', [PettyCashController::class, 'show'])->name('cash.show');
    Route::put('/caja-chica/{cash}', [PettyCashController::class, 'update'])->name('cash.update');
    Route::post('/caja-chica/{cash}/devoluciones', [PettyCashController::class, 'returnMoney'])->name('cash.returns');
    Route::post('/caja-chica/{cash}/estado', [PettyCashController::class, 'transition'])->name('cash.transition');
    Route::get('/caja-chica/{cash}/exportar/{format}', [ExportController::class, 'cash'])->whereIn('format', ['xlsx', 'pdf'])->name('cash.export');
    Route::get('/gastos', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::post('/gastos', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::put('/gastos/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::get('/gastos/{expense}/comprobante', [ExpenseController::class, 'receipt'])->name('expenses.receipt');
    Route::get('/gastos/{expense}/historial', [ExpenseController::class, 'history'])->name('expenses.history');
    Route::get('/reportes/{format}', ExportController::class)->whereIn('format', ['xlsx', 'pdf'])->name('reports.export');
    Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
    Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
    Route::put('/usuarios/{user}', [UserController::class, 'update'])->name('users.update');
    Route::get('/auditoria', AuditController::class)->name('audit.index');
});
