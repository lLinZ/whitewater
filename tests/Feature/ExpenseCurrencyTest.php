<?php

use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\ShoppingTrip;
use App\Models\User;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

/** Una tasa guardada: vigente el día $effective, descargada $fetched. */
function rate(string $effective, float $bcv, float $parallel, float $eur, ?string $fetched = null): ExchangeRate
{
    return ExchangeRate::create([
        'bcv_usd' => $bcv,
        'parallel_usd' => $parallel,
        'bcv_eur' => $eur,
        'rate_date' => $effective,
        'fetched_at' => $fetched ?? "{$effective} 08:00:00",
    ]);
}

function postExpense(User $user, array $data)
{
    return actingAs($user)->post('/finanzas/gastos', [
        'description' => 'Almuerzo',
        'date' => '2026-09-04',
        ...$data,
    ]);
}

// --- La tasa de un día ------------------------------------------------------

test('la tasa de un día es la vigente ese día, no la más reciente', function () {
    rate('2026-09-03', 800, 900, 870);
    $sept4 = rate('2026-09-04', 810, 910, 880);
    rate('2026-09-08', 830, 940, 900);

    expect(app(ExchangeRateService::class)->forDate('2026-09-04')->id)->toBe($sept4->id);
    // Un fin de semana rige la del viernes.
    expect(app(ExchangeRateService::class)->forDate('2026-09-06')->id)->toBe($sept4->id);
});

test('la tasa publicada por la tarde para el día siguiente no se usa ese mismo día', function () {
    $morning = rate('2026-09-04', 810, 910, 880, '2026-09-04 08:00:00');
    // El BCV publica el viernes a las 14:00 la del lunes.
    rate('2026-09-07', 820, 915, 890, '2026-09-04 14:00:00');

    expect(app(ExchangeRateService::class)->forDate('2026-09-04')->id)->toBe($morning->id);
});

test('de un mismo día cuenta la última descarga', function () {
    rate('2026-09-04', 810, 905, 880, '2026-09-04 08:00:00');
    $afternoon = rate('2026-09-04', 810, 915, 880, '2026-09-04 14:00:00');

    expect(app(ExchangeRateService::class)->forDate('2026-09-04')->id)->toBe($afternoon->id);
});

test('una tasa sin fecha de vigencia cuenta desde el día en que se descargó', function () {
    $rate = ExchangeRate::create(['bcv_usd' => 810, 'parallel_usd' => 910, 'bcv_eur' => 880, 'fetched_at' => '2026-09-04 09:00:00']);

    expect(app(ExchangeRateService::class)->forDate('2026-09-05')->id)->toBe($rate->id);
    expect($rate->effectiveDate())->toBe('2026-09-04');
});

test('antes de la primera tasa guardada se usa la primera', function () {
    $first = rate('2026-09-03', 800, 900, 870);
    rate('2026-09-04', 810, 910, 880);

    expect(app(ExchangeRateService::class)->forDate('2026-01-01')->id)->toBe($first->id);
});

test('sin ninguna tasa guardada no hay tasa', function () {
    expect(app(ExchangeRateService::class)->forDate('2026-09-04'))->toBeNull();
});

// --- Conversión -------------------------------------------------------------

test('toUsd es el camino inverso de convert', function () {
    $rates = ['bcv_usd' => 800.0, 'parallel_usd' => 1000.0, 'bcv_eur' => 900.0];

    expect(ExchangeRate::toUsd(10, 'USD', $rates))->toBe(10.0);
    expect(ExchangeRate::toUsd(8000, 'VES', $rates))->toBe(10.0);
    expect(ExchangeRate::toUsd(8, 'USDT', $rates))->toBe(10.0);   // 8 USDT = 8000 Bs
    expect(round(ExchangeRate::toUsd(8.89, 'EUR', $rates), 2))->toBe(10.0); // 8.89 € ≈ 8000 Bs

    // Y de vuelta, convert() da lo que se pagó.
    $back = ExchangeRate::convert(10, 800, 1000, 900);
    expect($back['bcv'])->toBe(8000.0);
    expect($back['usdt'])->toBe(8.0);
});

test('sin la tasa necesaria no se convierte', function () {
    expect(ExchangeRate::toUsd(100, 'VES', ['bcv_usd' => null, 'parallel_usd' => 900.0, 'bcv_eur' => 880.0]))->toBeNull();
    expect(ExchangeRate::toUsd(100, 'USDT', ['bcv_usd' => 800.0, 'parallel_usd' => null, 'bcv_eur' => 880.0]))->toBeNull();
    expect(ExchangeRate::toUsd(100, 'EUR', ['bcv_usd' => 800.0, 'parallel_usd' => 900.0, 'bcv_eur' => null]))->toBeNull();
    // En dólares no hace falta ninguna.
    expect(ExchangeRate::toUsd(100, 'USD', ['bcv_usd' => null, 'parallel_usd' => null, 'bcv_eur' => null]))->toBe(100.0);
});

// --- Registrar gastos en cualquier moneda ------------------------------------

test('un gasto en bolívares se guarda en dólares con la tasa del día del gasto', function () {
    rate('2026-09-04', 800, 1000, 900);
    rate('2026-09-11', 850, 1050, 950); // la de hoy: no debe usarse

    postExpense($this->user, ['amount' => 3400, 'currency' => 'VES'])->assertSessionHasNoErrors();

    $expense = Expense::sole();
    expect($expense->currency)->toBe('VES');
    expect((float) $expense->original_amount)->toBe(3400.0);
    expect((float) $expense->amount)->toBe(4.25); // 3400 / 800
    expect($expense->rates)->toBe(['bcv_usd' => 800.0, 'parallel_usd' => 1000.0, 'bcv_eur' => 900.0]);
});

test('un gasto en USDT pasa por los bolívares a la tasa paralela', function () {
    rate('2026-09-04', 800, 1000, 900);

    postExpense($this->user, ['amount' => 8, 'currency' => 'USDT']);

    expect((float) Expense::sole()->amount)->toBe(10.0); // 8 USDT = 8000 Bs = $10 BCV
});

test('un gasto en euros pasa por los bolívares a la tasa BCV del euro', function () {
    rate('2026-09-04', 800, 1000, 900);

    postExpense($this->user, ['amount' => 20, 'currency' => 'EUR']);

    expect((float) Expense::sole()->amount)->toBe(22.5); // 20 € = 18000 Bs = $22.50
});

test('un gasto en dólares queda igual y congela las tasas de su día', function () {
    rate('2026-09-04', 800, 1000, 900);

    postExpense($this->user, ['amount' => 8.5, 'currency' => 'USD']);

    $expense = Expense::sole();
    expect((float) $expense->amount)->toBe(8.5);
    expect((float) $expense->original_amount)->toBe(8.5);
    expect($expense->rates['bcv_usd'])->toBe(800.0);
});

test('sin moneda se entiende dólares', function () {
    postExpense($this->user, ['amount' => 8.5])->assertSessionHasNoErrors();

    expect(Expense::sole()->currency)->toBe('USD');
});

test('sin tasa, un gasto en bolívares se rechaza y en dólares se acepta', function () {
    postExpense($this->user, ['amount' => 3400, 'currency' => 'VES'])->assertSessionHasErrors('amount');
    expect(Expense::count())->toBe(0);

    postExpense($this->user, ['amount' => 8.5, 'currency' => 'USD'])->assertSessionHasNoErrors();
    expect(Expense::sole()->rates['bcv_usd'])->toBeNull();
});

test('una moneda desconocida se rechaza', function () {
    postExpense($this->user, ['amount' => 10, 'currency' => 'BTC'])->assertSessionHasErrors('currency');
});

test('un gasto creado sin moneda por otro camino queda en dólares', function () {
    // Como los del mercado: lo pagado es el monto.
    $expense = Expense::create(['amount' => 12.3, 'description' => 'X', 'date' => '2026-09-04']);

    expect($expense->currency)->toBe('USD');
    expect((float) $expense->fresh()->original_amount)->toBe(12.3);
});

// --- Editar -----------------------------------------------------------------

test('editar sin cambiar la fecha no mueve el monto aunque llegue otra tasa de ese día', function () {
    rate('2026-09-04', 800, 1000, 900, '2026-09-04 08:00:00');
    postExpense($this->user, ['amount' => 3400, 'currency' => 'VES']);
    $expense = Expense::sole();

    rate('2026-09-04', 820, 1020, 910, '2026-09-04 14:00:00');

    actingAs($this->user)->patch("/finanzas/gastos/{$expense->id}", [
        'amount' => 3400, 'currency' => 'VES', 'description' => 'Almuerzo con perros', 'date' => '2026-09-04',
    ])->assertSessionHasNoErrors();

    expect((float) $expense->fresh()->amount)->toBe(4.25);
    expect($expense->fresh()->rates['bcv_usd'])->toBe(800.0);
});

test('cambiar la fecha convierte con la tasa del nuevo día', function () {
    rate('2026-09-04', 800, 1000, 900);
    rate('2026-09-08', 850, 1050, 950);
    postExpense($this->user, ['amount' => 3400, 'currency' => 'VES']);
    $expense = Expense::sole();

    actingAs($this->user)->patch("/finanzas/gastos/{$expense->id}", [
        'amount' => 3400, 'currency' => 'VES', 'description' => 'Almuerzo', 'date' => '2026-09-08',
    ]);

    expect((float) $expense->fresh()->amount)->toBe(4.0); // 3400 / 850
});

test('se puede cambiar la moneda de un gasto viejo', function () {
    rate('2026-09-04', 800, 1000, 900);
    $expense = Expense::create(['amount' => 8.5, 'description' => 'Viejo', 'date' => '2026-09-04']);

    actingAs($this->user)->patch("/finanzas/gastos/{$expense->id}", [
        'amount' => 6800, 'currency' => 'VES', 'description' => 'Viejo', 'date' => '2026-09-04',
    ])->assertSessionHasNoErrors();

    expect($expense->fresh()->currency)->toBe('VES');
    expect((float) $expense->fresh()->amount)->toBe(8.5);
});

// --- Lo que ve la pantalla --------------------------------------------------

test('la lista de gastos trae moneda, lo pagado y las tasas, sin las columnas crudas', function () {
    rate('2026-09-04', 800, 1000, 900);
    postExpense($this->user, ['amount' => 3400, 'currency' => 'VES']);

    actingAs($this->user)->get('/finanzas')
        ->assertInertia(fn (Assert $p) => $p
            ->where('expenses.0.currency', 'VES')
            ->where('expenses.0.original_amount', '3400.00')
            ->where('expenses.0.rates.bcv_usd', 800)
            ->missing('expenses.0.rate_bcv_usd')
        );
});

test('la pantalla de gasto puede pedir las tasas de un día', function () {
    rate('2026-09-04', 800, 1000, 900);

    actingAs($this->user)->getJson('/tasas/dia?fecha=2026-09-05')
        ->assertOk()
        ->assertExactJson(['rates' => ['bcv_usd' => 800, 'parallel_usd' => 1000, 'bcv_eur' => 900, 'date' => '2026-09-04']]);
});

test('sin tasas guardadas la pantalla lo sabe', function () {
    actingAs($this->user)->getJson('/tasas/dia?fecha=2026-09-05')
        ->assertOk()
        ->assertExactJson(['rates' => null]);
});

// --- Mercado ----------------------------------------------------------------

test('el gasto de un mercado lleva las tasas del mercado', function () {
    $trip = ShoppingTrip::create(['name' => 'Mercado', 'status' => 'active', 'created_by' => $this->user->id])
        ->snapshotRates(['bcv_usd' => 827.7, 'parallel_usd' => 940.2, 'bcv_eur' => 963.2]);
    $trip->save();
    $trip->items()->create(['name' => 'Harina', 'quantity' => 1, 'unit_price_usd' => 10]);

    actingAs($this->user)->post("/mercado/{$trip->id}/terminar", ['as_expense' => true]);

    $expense = Expense::sole();
    expect($expense->currency)->toBe('USD');
    expect($expense->rates)->toBe($trip->fresh()->rates);
});
