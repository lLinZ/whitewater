<?php

use Illuminate\Support\Facades\Route;

use Inertia\Inertia;

use App\Http\Controllers\KitchenController;
use App\Http\Controllers\InventoryController;

Route::get('/', function () {
    return Inertia::render('Dashboard/Index');
});

// Rutas de Cocina - Recetas y Planner
Route::get('/kitchen/recipes', [KitchenController::class, 'recipes']);
Route::post('/kitchen/recipes', [KitchenController::class, 'storeRecipe']);
Route::put('/kitchen/recipes/{recipe}', [KitchenController::class, 'updateRecipe']);
Route::delete('/kitchen/recipes/{recipe}', [KitchenController::class, 'destroyRecipe']);

Route::get('/kitchen/planner', [KitchenController::class, 'planner']);
Route::post('/kitchen/planner', [KitchenController::class, 'assignPlan']);
Route::delete('/kitchen/planner/{plan}', [KitchenController::class, 'deletePlan']);

// Rutas de Inventario
Route::get('/kitchen/inventory', [InventoryController::class, 'index']);
Route::post('/kitchen/inventory', [InventoryController::class, 'store']);
Route::put('/kitchen/inventory/{ingredient}', [InventoryController::class, 'update']);
Route::delete('/kitchen/inventory/{ingredient}', [InventoryController::class, 'destroy']);

// Dummies enlazados a las nuevas vistas React
Route::get('/finances/expenses', function () { return Inertia::render('Finances/Expenses'); });
Route::get('/finances/budget', function () { return Inertia::render('Finances/Budget'); });
Route::get('/housekeeping', function () { return Inertia::render('Housekeeping/Index'); });
Route::get('/joppa/kanban', function () { return Inertia::render('Joppa/Kanban'); });
Route::get('/joppa/assets', function () { return Inertia::render('Joppa/Assets'); });
