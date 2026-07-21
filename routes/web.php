<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\SavingsController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\RatesController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\PushController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::middleware(['auth'])->group(function () {
    // Home / Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Perfil
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // --- Cocina ---
    Route::get('/cocina/recetas', [KitchenController::class, 'recipes'])->name('recipes.index');
    Route::post('/cocina/recetas', [KitchenController::class, 'storeRecipe'])->name('recipes.store');
    Route::put('/cocina/recetas/{recipe}', [KitchenController::class, 'updateRecipe'])->name('recipes.update');
    Route::delete('/cocina/recetas/{recipe}', [KitchenController::class, 'destroyRecipe'])->name('recipes.destroy');

    Route::get('/cocina/menu', [KitchenController::class, 'planner'])->name('planner.index');
    Route::post('/cocina/menu', [KitchenController::class, 'assignPlan'])->name('planner.store');
    Route::post('/cocina/menu/{plan}/cocinar', [KitchenController::class, 'cookPlan'])->name('planner.cook');
    Route::delete('/cocina/menu/{plan}', [KitchenController::class, 'deletePlan'])->name('planner.destroy');

    Route::get('/cocina/inventario', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/cocina/inventario', [InventoryController::class, 'store'])->name('inventory.store');
    Route::put('/cocina/inventario/{ingredient}', [InventoryController::class, 'update'])->name('inventory.update');
    Route::delete('/cocina/inventario/{ingredient}', [InventoryController::class, 'destroy'])->name('inventory.destroy');

    // --- Finanzas ---
    Route::get('/finanzas', [FinanceController::class, 'index'])->name('finance.index');
    Route::post('/finanzas/gastos', [FinanceController::class, 'storeExpense'])->name('finance.expenses.store');
    Route::delete('/finanzas/gastos/{expense}', [FinanceController::class, 'destroyExpense'])->name('finance.expenses.destroy');
    Route::post('/finanzas/categorias', [FinanceController::class, 'storeCategory'])->name('finance.categories.store');

    // --- Deudas & Ahorros ---
    Route::get('/dinero', [DebtController::class, 'index'])->name('money.index');
    Route::post('/dinero/deudas', [DebtController::class, 'storeDebt'])->name('debts.store');
    Route::post('/dinero/deudas/{debt}/abono', [DebtController::class, 'storePayment'])->name('debts.pay');
    Route::delete('/dinero/deudas/{debt}', [DebtController::class, 'destroyDebt'])->name('debts.destroy');

    Route::post('/dinero/metas', [SavingsController::class, 'storeGoal'])->name('savings.store');
    Route::post('/dinero/metas/{goal}/aporte', [SavingsController::class, 'storeContribution'])->name('savings.contribute');
    Route::delete('/dinero/metas/{goal}', [SavingsController::class, 'destroyGoal'])->name('savings.destroy');

    // --- Tasas de cambio ---
    Route::post('/tasas/refrescar', [RatesController::class, 'refresh'])->name('rates.refresh');

    // --- Mercado (compras) ---
    Route::get('/mercado', [MarketController::class, 'index'])->name('market.index');
    Route::post('/mercado', [MarketController::class, 'store'])->name('market.store');
    Route::get('/mercado/{trip}', [MarketController::class, 'show'])->name('market.show');
    Route::post('/mercado/{trip}/item', [MarketController::class, 'addItem'])->name('market.items.add');
    Route::patch('/mercado/{trip}/item/{item}', [MarketController::class, 'updateItem'])->name('market.items.update');
    Route::delete('/mercado/{trip}/item/{item}', [MarketController::class, 'deleteItem'])->name('market.items.delete');
    Route::post('/mercado/{trip}/terminar', [MarketController::class, 'finish'])->name('market.finish');
    Route::delete('/mercado/{trip}', [MarketController::class, 'destroy'])->name('market.destroy');

    // --- Notificaciones push ---
    Route::post('/notificaciones/suscribir', [PushController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/notificaciones/desuscribir', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::post('/notificaciones/probar', [PushController::class, 'test'])->name('push.test');

    // --- Hogar (rutinas) ---
    Route::get('/hogar', [RoutineController::class, 'index'])->name('routines.index');
    Route::post('/hogar', [RoutineController::class, 'store'])->name('routines.store');
    Route::post('/hogar/{routine}/completar', [RoutineController::class, 'complete'])->name('routines.complete');
    Route::delete('/hogar/{routine}', [RoutineController::class, 'destroy'])->name('routines.destroy');
});

require __DIR__.'/auth.php';
