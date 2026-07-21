<?php

use App\Models\User;
use App\Models\ExchangeRate;
use App\Models\ShoppingTrip;
use App\Models\Expense;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function fakeDolarApi(): void
{
    Http::fake([
        've.dolarapi.com/v1/dolares' => Http::response([
            ['moneda' => 'USD', 'fuente' => 'oficial', 'promedio' => 700, 'fechaActualizacion' => '2026-07-10T00:00:00-04:00'],
            ['moneda' => 'USD', 'fuente' => 'paralelo', 'promedio' => 800, 'fechaActualizacion' => '2026-07-12T15:00:00Z'],
        ]),
        've.dolarapi.com/v1/euros' => Http::response([
            ['moneda' => 'EUR', 'fuente' => 'oficial', 'promedio' => 810],
            ['moneda' => 'EUR', 'fuente' => 'paralelo', 'promedio' => 930],
        ]),
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('el servicio obtiene y guarda las tasas', function () {
    fakeDolarApi();
    $rate = app(ExchangeRateService::class)->fetch();

    expect($rate)->not->toBeNull();
    expect((float) $rate->bcv_usd)->toBe(700.0);
    expect((float) $rate->parallel_usd)->toBe(800.0);
    expect((float) $rate->bcv_eur)->toBe(810.0);
});

test('la conversión USD -> BCV/USDT/EUR es correcta', function () {
    $c = ExchangeRate::convert(10, 700, 800, 810);
    expect($c['bcv'])->toBe(7000.0);
    expect($c['usdt'])->toBe(8000.0);
    expect($c['eur'])->toBe(round(10 * 700 / 810, 2)); // 8.64
});

test('el comando rates:sync guarda una tasa', function () {
    fakeDolarApi();
    $this->artisan('rates:sync')->assertSuccessful();
    expect(ExchangeRate::count())->toBe(1);
});

test('el endpoint de refresco actualiza tasas', function () {
    fakeDolarApi();
    actingAs($this->user)->post('/tasas/refrescar')->assertRedirect();
    expect(ExchangeRate::count())->toBeGreaterThan(0);
});

test('crear un mercado toma un snapshot de la tasa vigente', function () {
    ExchangeRate::create([
        'bcv_usd' => 700, 'parallel_usd' => 800, 'bcv_eur' => 810,
        'source' => 'test', 'fetched_at' => now(),
    ]);
    Http::fake(); // por si acaso current() intentara refrescar

    actingAs($this->user)->post('/mercado', ['name' => 'Prueba'])->assertRedirect();

    $trip = ShoppingTrip::first();
    expect((float) $trip->rate_bcv_usd)->toBe(700.0);
    expect((float) $trip->rate_parallel_usd)->toBe(800.0);
});

test('empezar un mercado sin nombre usa uno por defecto', function () {
    ExchangeRate::create(['bcv_usd' => 700, 'parallel_usd' => 800, 'bcv_eur' => 810, 'source' => 'test', 'fetched_at' => now()]);
    Http::fake();

    actingAs($this->user)->post('/mercado', [])->assertRedirect();

    $trip = ShoppingTrip::first();
    expect($trip)->not->toBeNull();
    expect($trip->name)->toStartWith('Mercado ');
});

test('flujo de mercado: agregar items, total y terminar como gasto', function () {
    Http::fake();
    ExchangeRate::create(['bcv_usd' => 700, 'parallel_usd' => 800, 'bcv_eur' => 810, 'source' => 'test', 'fetched_at' => now()]);
    actingAs($this->user);

    $this->post('/mercado', ['name' => 'Mercado test'])->assertRedirect();
    $trip = ShoppingTrip::first();

    $this->post("/mercado/{$trip->id}/item", ['name' => 'Arroz', 'unit_price_usd' => 2.5, 'quantity' => 2])->assertRedirect();
    $this->post("/mercado/{$trip->id}/item", ['name' => 'Pollo', 'unit_price_usd' => 5, 'quantity' => 1])->assertRedirect();

    expect($trip->fresh()->total_usd)->toBe(10.0); // 2.5*2 + 5

    $this->post("/mercado/{$trip->id}/terminar", ['as_expense' => true])->assertRedirect();

    $trip->refresh();
    expect($trip->status)->toBe('done');
    expect($trip->expense_id)->not->toBeNull();
    expect((float) Expense::find($trip->expense_id)->amount)->toBe(10.0);
});

test('eliminar un item ajena a otro mercado da 404', function () {
    actingAs($this->user);
    $t1 = ShoppingTrip::create(['name' => 'A', 'status' => 'active', 'created_by' => $this->user->id]);
    $t2 = ShoppingTrip::create(['name' => 'B', 'status' => 'active', 'created_by' => $this->user->id]);
    $item = $t2->items()->create(['name' => 'x', 'unit_price_usd' => 1, 'quantity' => 1]);

    $this->delete("/mercado/{$t1->id}/item/{$item->id}")->assertNotFound();
    expect($t2->items()->count())->toBe(1);
});

test('las páginas de mercado renderizan', function () {
    ExchangeRate::create(['bcv_usd' => 700, 'parallel_usd' => 800, 'bcv_eur' => 810, 'source' => 'test', 'fetched_at' => now()]);
    Http::fake();
    actingAs($this->user);
    $trip = ShoppingTrip::create(['name' => 'T', 'status' => 'active', 'created_by' => $this->user->id]);

    $this->get('/mercado')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Market/Index'));
    $this->get("/mercado/{$trip->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('Market/Show'));
});

test('el catálogo sugiere productos ya comprados con su último precio', function () {
    ExchangeRate::create(['bcv_usd' => 700, 'parallel_usd' => 800, 'bcv_eur' => 810, 'source' => 'test', 'fetched_at' => now()]);
    Http::fake();
    actingAs($this->user);

    $t1 = ShoppingTrip::create(['name' => 'A', 'status' => 'done', 'created_by' => $this->user->id]);
    $t1->items()->create(['name' => 'Harina PAN', 'unit_price_usd' => 1.20, 'quantity' => 2]);
    $t1->items()->create(['name' => 'Harina PAN', 'unit_price_usd' => 1.50, 'quantity' => 1]); // más reciente

    $t2 = ShoppingTrip::create(['name' => 'B', 'status' => 'active', 'created_by' => $this->user->id]);

    $this->get("/mercado/{$t2->id}")
        ->assertInertia(fn (Assert $p) => $p
            ->component('Market/Show')
            ->has('catalog', 1)
            ->where('catalog.0.name', 'Harina PAN')
            ->where('catalog.0.count', 2)
            ->where('catalog.0.last_price', 1.5)
        );
});

test('un producto se puede anotar sin precio y completarlo después', function () {
    Http::fake();
    actingAs($this->user);
    $trip = ShoppingTrip::create(['name' => 'A', 'status' => 'active', 'created_by' => $this->user->id]);

    $this->post("/mercado/{$trip->id}/item", ['name' => 'Arroz', 'quantity' => 2])->assertRedirect();

    $trip->refresh();
    expect($trip->items()->first()->unit_price_usd)->toBeNull();
    expect($trip->pending_price_count)->toBe(1);
    expect($trip->total_usd)->toBe(0.0);

    $item = $trip->items()->first();
    $this->patch("/mercado/{$trip->id}/item/{$item->id}", [
        'name' => 'Arroz', 'unit_price_usd' => 3, 'quantity' => 2,
    ])->assertRedirect();

    $trip->refresh();
    expect($trip->pending_price_count)->toBe(0);
    expect($trip->total_usd)->toBe(6.0);
});

test('marca y presentación distinguen productos del mismo nombre', function () {
    ExchangeRate::create(['bcv_usd' => 700, 'parallel_usd' => 800, 'bcv_eur' => 810, 'source' => 'test', 'fetched_at' => now()]);
    Http::fake();
    actingAs($this->user);

    $t1 = ShoppingTrip::create(['name' => 'A', 'status' => 'done', 'created_by' => $this->user->id]);
    $t1->items()->create(['name' => 'Café', 'brand' => 'Amanecer', 'size' => '1 kg', 'unit_price_usd' => 9, 'quantity' => 1]);
    $t1->items()->create(['name' => 'Café', 'brand' => 'Amanecer', 'size' => '500 g', 'unit_price_usd' => 5, 'quantity' => 1]);

    $t2 = ShoppingTrip::create(['name' => 'B', 'status' => 'active', 'created_by' => $this->user->id]);

    $this->get("/mercado/{$t2->id}")
        ->assertInertia(fn (Assert $p) => $p
            ->component('Market/Show')
            ->has('catalog', 2)
            ->where('catalog.0.label', 'Café · Amanecer · 500 g')
            ->where('catalog.1.label', 'Café · Amanecer · 1 kg')
        );
});

test('el último precio del catálogo ignora los productos sin precio', function () {
    ExchangeRate::create(['bcv_usd' => 700, 'parallel_usd' => 800, 'bcv_eur' => 810, 'source' => 'test', 'fetched_at' => now()]);
    Http::fake();
    actingAs($this->user);

    $t1 = ShoppingTrip::create(['name' => 'A', 'status' => 'done', 'created_by' => $this->user->id]);
    $t1->items()->create(['name' => 'Arroz', 'unit_price_usd' => 1.80, 'quantity' => 1]);
    $t1->items()->create(['name' => 'Arroz', 'unit_price_usd' => null, 'quantity' => 1]); // anotado, sin precio

    $t2 = ShoppingTrip::create(['name' => 'B', 'status' => 'active', 'created_by' => $this->user->id]);

    $this->get("/mercado/{$t2->id}")
        ->assertInertia(fn (Assert $p) => $p->where('catalog.0.last_price', 1.8));
});

test('editar un item ajeno a otro mercado da 404', function () {
    actingAs($this->user);
    $t1 = ShoppingTrip::create(['name' => 'A', 'status' => 'active', 'created_by' => $this->user->id]);
    $t2 = ShoppingTrip::create(['name' => 'B', 'status' => 'active', 'created_by' => $this->user->id]);
    $item = $t2->items()->create(['name' => 'x', 'unit_price_usd' => 1, 'quantity' => 1]);

    $this->patch("/mercado/{$t1->id}/item/{$item->id}", ['name' => 'y'])->assertNotFound();
    expect($item->fresh()->name)->toBe('x');
});

test('las tasas se comparten a todas las vistas Inertia', function () {
    ExchangeRate::create(['bcv_usd' => 700, 'parallel_usd' => 800, 'bcv_eur' => 810, 'source' => 'test', 'fetched_at' => now()]);
    Http::fake();
    actingAs($this->user)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p->has('rates.bcv_usd'));
});
