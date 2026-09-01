<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->other = User::factory()->create(['name' => 'Otro']);
    $this->market = ExpenseCategory::create(['name' => 'Mercado']);
    $this->transport = ExpenseCategory::create(['name' => 'Transporte']);
});

function expense(array $attributes = []): Expense
{
    return Expense::create([
        'amount' => 10,
        'description' => 'Gasto',
        'date' => '2026-08-15',
        ...$attributes,
    ]);
}

test('la portada de gastos adelanta 10 y dice el total que hay', function () {
    for ($i = 0; $i < 14; $i++) {
        expense(['description' => "Gasto {$i}"]);
    }

    actingAs($this->user)->get('/finanzas')
        ->assertInertia(fn (Assert $p) => $p->has('expenses', 10)->where('expenseCount', 14));
});

test('el historial lista todos los gastos paginados', function () {
    for ($i = 0; $i < 45; $i++) {
        expense(['description' => "Gasto {$i}"]);
    }

    actingAs($this->user)->get('/finanzas/historial')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Finance/History')
            ->has('expenses.data', 30)
            ->where('expenses.last_page', 2)
            ->where('totals.count', 45)
            ->where('totals.sum', 450)
        );

    actingAs($this->user)->get('/finanzas/historial?page=2')
        ->assertInertia(fn (Assert $p) => $p->has('expenses.data', 15));
});

test('se puede buscar un gasto viejo por su descripción', function () {
    expense(['description' => 'Gasolina, tanque completo', 'date' => '2026-07-12']);
    expense(['description' => 'Compras Kosmos', 'date' => '2026-07-12']);

    actingAs($this->user)->get('/finanzas/historial?q=gasolina')
        ->assertInertia(fn (Assert $p) => $p
            ->has('expenses.data', 1)
            ->where('expenses.data.0.description', 'Gasolina, tanque completo')
            ->where('totals.count', 1)
        );
});

test('se puede filtrar por categoría', function () {
    expense(['expense_category_id' => $this->market->id, 'amount' => 30]);
    expense(['expense_category_id' => $this->transport->id, 'amount' => 12]);

    actingAs($this->user)->get("/finanzas/historial?category={$this->transport->id}")
        ->assertInertia(fn (Assert $p) => $p->has('expenses.data', 1)->where('totals.sum', 12));
});

test('se puede filtrar por quién lo registró', function () {
    expense(['created_by' => $this->user->id]);
    expense(['created_by' => $this->other->id]);
    expense(['created_by' => $this->other->id]);

    actingAs($this->user)->get("/finanzas/historial?member={$this->other->id}")
        ->assertInertia(fn (Assert $p) => $p->has('expenses.data', 2));
});

test('se puede acotar por rango de fechas', function () {
    expense(['date' => '2026-06-10']);
    expense(['date' => '2026-07-10']);
    expense(['date' => '2026-08-10']);

    actingAs($this->user)->get('/finanzas/historial?from=2026-07-01&to=2026-07-31')
        ->assertInertia(fn (Assert $p) => $p
            ->has('expenses.data', 1)
            ->where('expenses.data.0.date', '2026-07-10')
        );
});

test('se pueden ver solo los gastos con comprobante', function () {
    expense(['description' => 'Con recibo', 'receipt_path' => 'receipts/uno.jpg']);
    expense(['description' => 'Sin recibo']);

    actingAs($this->user)->get('/finanzas/historial?receipts=1')
        ->assertInertia(fn (Assert $p) => $p
            ->has('expenses.data', 1)
            ->where('expenses.data.0.description', 'Con recibo')
        );
});

test('los filtros se combinan y el total refleja lo filtrado', function () {
    expense(['description' => 'Gasolina', 'amount' => 12, 'date' => '2026-07-12', 'expense_category_id' => $this->transport->id]);
    expense(['description' => 'Gasolina', 'amount' => 15, 'date' => '2026-08-12', 'expense_category_id' => $this->transport->id]);
    expense(['description' => 'Mercado', 'amount' => 90, 'date' => '2026-08-12', 'expense_category_id' => $this->market->id]);

    actingAs($this->user)->get("/finanzas/historial?q=gasolina&category={$this->transport->id}&from=2026-08-01")
        ->assertInertia(fn (Assert $p) => $p->where('totals.count', 1)->where('totals.sum', 15));
});

test('el historial devuelve los filtros para poder repintarlos', function () {
    actingAs($this->user)->get('/finanzas/historial?q=luz&receipts=1')
        ->assertInertia(fn (Assert $p) => $p
            ->where('filters.q', 'luz')
            ->where('filters.receipts', '1')
            ->where('filters.category', '')
        );
});

test('los gastos salen del más nuevo al más viejo', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-31'));

    expense(['description' => 'Viejo', 'date' => '2026-06-01']);
    expense(['description' => 'Nuevo', 'date' => '2026-08-30']);

    actingAs($this->user)->get('/finanzas/historial')
        ->assertInertia(fn (Assert $p) => $p->where('expenses.data.0.description', 'Nuevo'));

    Carbon::setTestNow();
});
