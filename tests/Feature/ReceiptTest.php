<?php

use App\Models\Debt;
use App\Models\Expense;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    $this->user = User::factory()->create();
});

/** Foto de recibo de mentira, del tamaño típico de una captura del banco. */
function fakeReceipt(string $name = 'recibo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 1000, 1400);
}

test('un gasto se puede registrar con la foto del recibo', function () {
    actingAs($this->user)->post('/finanzas/gastos', [
        'amount' => '45.50',
        'description' => 'Mercado del mes',
        'date' => '2026-08-20',
        'receipt' => fakeReceipt(),
    ])->assertRedirect();

    $expense = Expense::first();
    expect($expense->receipt_path)->not->toBeNull();
    expect($expense->receipt_url)->toContain('/storage/');
    Storage::disk('public')->assertExists($expense->receipt_path);
});

test('el recibo es opcional: sin foto el gasto se registra igual', function () {
    actingAs($this->user)->post('/finanzas/gastos', [
        'amount' => '10',
        'description' => 'Café',
        'date' => '2026-08-20',
    ])->assertRedirect();

    expect(Expense::first()->receipt_path)->toBeNull();
});

test('borrar un gasto borra también su recibo del disco', function () {
    actingAs($this->user)->post('/finanzas/gastos', [
        'amount' => '20', 'description' => 'Gasolina', 'date' => '2026-08-20', 'receipt' => fakeReceipt(),
    ]);

    $expense = Expense::first();
    $path = $expense->receipt_path;

    actingAs($this->user)->delete("/finanzas/gastos/{$expense->id}")->assertRedirect();

    Storage::disk('public')->assertMissing($path);
});

test('un abono a una deuda guarda su comprobante', function () {
    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);

    actingAs($this->user)->post("/dinero/deudas/{$debt->id}/abono", [
        'amount' => '500', 'date' => '2026-08-13', 'receipt' => fakeReceipt('transferencia.jpg'),
    ])->assertRedirect();

    $payment = $debt->payments()->first();
    expect($payment->receipt_path)->not->toBeNull();
    Storage::disk('public')->assertExists($payment->receipt_path);
});

test('un aporte a una meta guarda su comprobante', function () {
    $goal = SavingsGoal::create(['name' => 'Crotone', 'target_amount' => 3000]);

    actingAs($this->user)->post("/dinero/metas/{$goal->id}/aporte", [
        'amount' => '250', 'date' => '2026-08-13', 'receipt' => fakeReceipt(),
    ])->assertRedirect();

    expect($goal->contributions()->first()->receipt_path)->not->toBeNull();
});

test('no se acepta un archivo que no sea imagen como comprobante', function () {
    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);

    actingAs($this->user)->post("/dinero/deudas/{$debt->id}/abono", [
        'amount' => '500', 'date' => '2026-08-13',
        'receipt' => UploadedFile::fake()->create('virus.pdf', 100),
    ])->assertSessionHasErrors('receipt');

    expect($debt->payments()->count())->toBe(0);
});

test('borrar una deuda limpia los recibos de todos sus abonos', function () {
    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);

    foreach (['a.jpg', 'b.jpg'] as $name) {
        actingAs($this->user)->post("/dinero/deudas/{$debt->id}/abono", [
            'amount' => '100', 'date' => '2026-08-13', 'receipt' => fakeReceipt($name),
        ]);
    }

    $paths = $debt->payments()->pluck('receipt_path');
    expect($paths)->toHaveCount(2);

    actingAs($this->user)->delete("/dinero/deudas/{$debt->id}")->assertRedirect();

    // Las filas se van en cascada; las fotos hay que borrarlas a mano.
    $paths->each(fn ($path) => Storage::disk('public')->assertMissing($path));
});

test('borrar una meta limpia los recibos de sus aportes', function () {
    $goal = SavingsGoal::create(['name' => 'Crotone', 'target_amount' => 3000]);

    actingAs($this->user)->post("/dinero/metas/{$goal->id}/aporte", [
        'amount' => '100', 'date' => '2026-08-13', 'receipt' => fakeReceipt(),
    ]);

    $path = $goal->contributions()->first()->receipt_path;

    actingAs($this->user)->delete("/dinero/metas/{$goal->id}")->assertRedirect();

    Storage::disk('public')->assertMissing($path);
});

test('el comprobante llega al front como URL, nunca como ruta interna', function () {
    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);
    actingAs($this->user)->post("/dinero/deudas/{$debt->id}/abono", [
        'amount' => '500', 'date' => '2026-08-13', 'receipt' => fakeReceipt(),
    ]);

    actingAs($this->user)->get("/dinero/deudas/{$debt->id}")
        ->assertInertia(fn (Assert $p) => $p
            ->component('Money/Show')
            ->where('entries.data.0.receipt_url', fn ($url) => str_contains((string) $url, '/storage/'))
        );
});

test('el comprobante se reduce a 1600px de lado mayor', function () {
    actingAs($this->user)->post('/finanzas/gastos', [
        'amount' => '10', 'description' => 'Foto enorme', 'date' => '2026-08-20',
        'receipt' => UploadedFile::fake()->image('enorme.jpg', 4000, 3000),
    ]);

    $path = Expense::first()->receipt_path;
    [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));

    expect($width)->toBe(1600);
    expect($height)->toBe(1200); // se conserva la proporción, no se recorta
})->skip(! extension_loaded('gd'), 'Requiere la extensión GD');

test('un comprobante pequeño no se agranda', function () {
    actingAs($this->user)->post('/finanzas/gastos', [
        'amount' => '10', 'description' => 'Foto chica', 'date' => '2026-08-20',
        'receipt' => UploadedFile::fake()->image('chica.jpg', 600, 400),
    ]);

    [$width, $height] = getimagesizefromstring(Storage::disk('public')->get(Expense::first()->receipt_path));

    expect($width)->toBe(600);
    expect($height)->toBe(400);
})->skip(! extension_loaded('gd'), 'Requiere la extensión GD');

test('el campo de comprobante vacío no rompe el guardado', function () {
    // Inertia manda los nulos como cadena vacía al usar FormData; si la regla
    // no lo tolerara, registrar un abono sin foto fallaría desde el teléfono.
    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);

    actingAs($this->user)->post("/dinero/deudas/{$debt->id}/abono", [
        'amount' => '500', 'date' => '2026-08-13', 'note' => '', 'receipt' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($debt->payments()->count())->toBe(1);
    expect($debt->payments()->first()->receipt_path)->toBeNull();
});
