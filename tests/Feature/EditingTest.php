<?php

use App\Models\Debt;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    $this->user = User::factory()->create();
});

function receiptFile(string $name = 'recibo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 900, 1200);
}

// --- Gastos ---------------------------------------------------------------

test('se puede editar un gasto ya registrado', function () {
    $category = ExpenseCategory::create(['name' => 'Mercado']);
    $expense = Expense::create([
        'amount' => 20, 'description' => 'Mercado', 'date' => '2026-08-10', 'created_by' => $this->user->id,
    ]);

    actingAs($this->user)->patch("/finanzas/gastos/{$expense->id}", [
        'amount' => '35.50',
        'description' => 'Mercado del mes',
        'date' => '2026-08-11',
        'expense_category_id' => $category->id,
    ])->assertRedirect();

    $expense->refresh();
    expect((float) $expense->amount)->toBe(35.50);
    expect($expense->description)->toBe('Mercado del mes');
    expect($expense->date->toDateString())->toBe('2026-08-11');
    expect($expense->expense_category_id)->toBe($category->id);
});

test('a un gasto viejo sin comprobante se le puede adjuntar la factura', function () {
    // El caso que motivó todo esto: el gasto se anotó hace semanas y la
    // factura aparece después.
    $expense = Expense::create([
        'amount' => 45, 'description' => 'Gasolina', 'date' => '2026-07-12', 'created_by' => $this->user->id,
    ]);
    expect($expense->receipt_path)->toBeNull();

    actingAs($this->user)->post("/finanzas/gastos/{$expense->id}", [
        '_method' => 'patch',
        'amount' => '45', 'description' => 'Gasolina', 'date' => '2026-07-12',
        'receipt' => receiptFile(),
    ])->assertRedirect();

    $expense->refresh();
    expect($expense->receipt_path)->not->toBeNull();
    Storage::disk('public')->assertExists($expense->receipt_path);
});

test('cambiar el comprobante borra el anterior del disco', function () {
    $expense = Expense::create([
        'amount' => 45, 'description' => 'Gasolina', 'date' => '2026-07-12', 'created_by' => $this->user->id,
    ]);

    actingAs($this->user)->post("/finanzas/gastos/{$expense->id}", [
        '_method' => 'patch', 'amount' => '45', 'description' => 'Gasolina', 'date' => '2026-07-12',
        'receipt' => receiptFile('primero.jpg'),
    ]);
    $first = $expense->fresh()->receipt_path;

    actingAs($this->user)->post("/finanzas/gastos/{$expense->id}", [
        '_method' => 'patch', 'amount' => '45', 'description' => 'Gasolina', 'date' => '2026-07-12',
        'receipt' => receiptFile('segundo.jpg'),
    ]);
    $second = $expense->fresh()->receipt_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

test('se puede quitar el comprobante de un gasto', function () {
    $expense = Expense::create([
        'amount' => 45, 'description' => 'Gasolina', 'date' => '2026-07-12', 'created_by' => $this->user->id,
    ]);

    actingAs($this->user)->post("/finanzas/gastos/{$expense->id}", [
        '_method' => 'patch', 'amount' => '45', 'description' => 'Gasolina', 'date' => '2026-07-12',
        'receipt' => receiptFile(),
    ]);
    $path = $expense->fresh()->receipt_path;

    actingAs($this->user)->patch("/finanzas/gastos/{$expense->id}", [
        'amount' => '45', 'description' => 'Gasolina', 'date' => '2026-07-12',
        'remove_receipt' => '1',
    ])->assertRedirect();

    expect($expense->fresh()->receipt_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('editar un gasto sin tocar el adjunto conserva el comprobante', function () {
    $expense = Expense::create([
        'amount' => 45, 'description' => 'Gasolina', 'date' => '2026-07-12', 'created_by' => $this->user->id,
    ]);

    actingAs($this->user)->post("/finanzas/gastos/{$expense->id}", [
        '_method' => 'patch', 'amount' => '45', 'description' => 'Gasolina', 'date' => '2026-07-12',
        'receipt' => receiptFile(),
    ]);
    $path = $expense->fresh()->receipt_path;

    actingAs($this->user)->patch("/finanzas/gastos/{$expense->id}", [
        'amount' => '50', 'description' => 'Gasolina tanque lleno', 'date' => '2026-07-12',
    ])->assertRedirect();

    $expense->refresh();
    expect((float) $expense->amount)->toBe(50.0);
    expect($expense->receipt_path)->toBe($path);
    Storage::disk('public')->assertExists($path);
});

// --- Abonos y aportes -----------------------------------------------------

test('se puede editar un abono y adjuntarle el recibo', function () {
    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);
    $payment = $debt->payments()->create([
        'amount' => 300, 'date' => '2026-07-13', 'paid_by' => $this->user->id,
    ]);

    actingAs($this->user)->post("/dinero/deudas/{$debt->id}/abono/{$payment->id}", [
        '_method' => 'patch',
        'amount' => '350', 'date' => '2026-07-14', 'note' => 'Binance',
        'receipt' => receiptFile(),
    ])->assertRedirect();

    $payment->refresh();
    expect((float) $payment->amount)->toBe(350.0);
    expect($payment->note)->toBe('Binance');
    expect($payment->receipt_path)->not->toBeNull();
});

test('se puede editar un aporte a una meta', function () {
    $goal = SavingsGoal::create(['name' => 'Crotone', 'target_amount' => 3000]);
    $contribution = $goal->contributions()->create([
        'amount' => 200, 'date' => '2026-08-01', 'contributed_by' => $this->user->id,
    ]);

    actingAs($this->user)->patch("/dinero/metas/{$goal->id}/aporte/{$contribution->id}", [
        'amount' => '250', 'date' => '2026-08-02', 'note' => 'Ahorro extra',
    ])->assertRedirect();

    $contribution->refresh();
    expect((float) $contribution->amount)->toBe(250.0);
    expect($contribution->note)->toBe('Ahorro extra');
});

test('no se puede editar un abono a través de otra deuda', function () {
    $mine = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);
    $other = Debt::create(['name' => 'Otra', 'total_amount' => 100]);
    $payment = $mine->payments()->create(['amount' => 300, 'date' => '2026-07-13', 'paid_by' => $this->user->id]);

    actingAs($this->user)->patch("/dinero/deudas/{$other->id}/abono/{$payment->id}", [
        'amount' => '99999', 'date' => '2026-07-13',
    ])->assertNotFound();

    expect((float) $payment->fresh()->amount)->toBe(300.0);
});

test('editar un abono con un monto inválido no lo modifica', function () {
    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);
    $payment = $debt->payments()->create(['amount' => 300, 'date' => '2026-07-13', 'paid_by' => $this->user->id]);

    actingAs($this->user)->patch("/dinero/deudas/{$debt->id}/abono/{$payment->id}", [
        'amount' => '0', 'date' => '2026-07-13',
    ])->assertSessionHasErrors('amount');

    expect((float) $payment->fresh()->amount)->toBe(300.0);
});

// --- Categorías -----------------------------------------------------------

test('se puede renombrar y recolorear una categoría', function () {
    $category = ExpenseCategory::create(['name' => 'Mercado', 'color' => '#7c3aed']);

    actingAs($this->user)->patch("/finanzas/categorias/{$category->id}", [
        'name' => 'Supermercado', 'color' => '#16a34a',
    ])->assertRedirect();

    $category->refresh();
    expect($category->name)->toBe('Supermercado');
    expect($category->color)->toBe('#16a34a');
});

test('borrar una categoría deja sus gastos sin categoría, no los borra', function () {
    $category = ExpenseCategory::create(['name' => 'Mercado']);
    Expense::create([
        'amount' => 30, 'description' => 'Compra', 'date' => '2026-08-10',
        'expense_category_id' => $category->id, 'created_by' => $this->user->id,
    ]);

    actingAs($this->user)->delete("/finanzas/categorias/{$category->id}")->assertRedirect();

    expect(ExpenseCategory::count())->toBe(0);
    expect(Expense::count())->toBe(1);
    expect(Expense::first()->expense_category_id)->toBeNull();
});

test('la pantalla de gastos dice cuántos gastos usa cada categoría', function () {
    $category = ExpenseCategory::create(['name' => 'Mercado']);
    Expense::create(['amount' => 10, 'description' => 'A', 'date' => '2026-08-10', 'expense_category_id' => $category->id]);
    Expense::create(['amount' => 10, 'description' => 'B', 'date' => '2026-08-10', 'expense_category_id' => $category->id]);

    actingAs($this->user)->get('/finanzas')
        ->assertInertia(fn ($p) => $p->where('categories.0.expenses_count', 2));
});
