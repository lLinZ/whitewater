<?php

use App\Models\ExchangeRate;
use App\Models\ShoppingTrip;
use App\Models\User;
use App\Services\InvoiceScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    config([
        'services.anthropic.key' => 'sk-ant-de-mentira',
        'services.gemini.key' => null,
        'services.invoice_scanner.driver' => 'auto',
    ]);
    $this->user = User::factory()->create();
});

/** Lo que devuelve el lector para una factura de supermercado corriente. */
function scannedInvoice(array $overrides = []): array
{
    return [
        'store' => 'Supermercado Kosmos',
        'date' => '2026-08-28',
        'currency' => 'VES',
        'items' => [
            ['name' => 'Harina', 'brand' => 'P.A.N.', 'size' => '1 kg', 'quantity' => 2.0, 'unit_price' => 90.0],
            ['name' => 'Leche', 'brand' => null, 'size' => '1 L', 'quantity' => 1.0, 'unit_price' => 120.0],
        ],
        'subtotal' => 300.0,
        'tax' => 48.0,
        'total' => 300.0,
        'items_total' => 300.0,
        'confidence' => 'alta',
        'notes' => null,
        ...$overrides,
    ];
}

/** Sustituye el lector por uno que no llama a la API (ni cuesta dinero). */
function fakeScanner(array $result = null): void
{
    $scanner = Mockery::mock(InvoiceScanner::class);
    $scanner->shouldReceive('isConfigured')->andReturn(true);
    $scanner->shouldReceive('scan')->andReturn($result ?? scannedInvoice());
    app()->instance(InvoiceScanner::class, $scanner);
}

test('sin clave configurada el escaneo no existe', function () {
    config(['services.anthropic.key' => null, 'services.gemini.key' => null]);

    actingAs($this->user)
        ->post('/mercado/escanear', ['invoice' => UploadedFile::fake()->image('factura.jpg')])
        ->assertNotFound();
});

test('sin clave el front no anuncia la función', function () {
    config(['services.anthropic.key' => null, 'services.gemini.key' => null]);

    actingAs($this->user)->get('/mercado')
        ->assertInertia(fn (Assert $p) => $p->where('features.invoiceScan', false));
});

test('con clave el front la anuncia', function () {
    actingAs($this->user)->get('/mercado')
        ->assertInertia(fn (Assert $p) => $p->where('features.invoiceScan', true));
});

test('escanear guarda la foto y manda a revisar', function () {
    fakeScanner();

    actingAs($this->user)
        ->post('/mercado/escanear', ['invoice' => UploadedFile::fake()->image('factura.jpg', 1200, 1600)])
        ->assertRedirect('/mercado/factura')
        ->assertSessionHas('invoice_draft');

    // Nada se crea todavía: primero hay que revisarlo.
    expect(ShoppingTrip::count())->toBe(0);
});

test('la pantalla de revisión muestra lo leído y las tasas del día', function () {
    ExchangeRate::create([
        'bcv_usd' => 40, 'parallel_usd' => 50, 'bcv_eur' => 44,
        'fetched_at' => now(), 'rate_date' => now()->toDateString(),
    ]);
    fakeScanner();

    actingAs($this->user)->post('/mercado/escanear', ['invoice' => UploadedFile::fake()->image('f.jpg')]);

    actingAs($this->user)->get('/mercado/factura')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Market/Invoice')
            ->where('invoice.store', 'Supermercado Kosmos')
            ->where('invoice.currency', 'VES')
            ->has('invoice.items', 2)
            ->where('rates.parallel_usd', 50)
            ->where('receiptUrl', fn ($url) => str_contains((string) $url, '/storage/'))
        );
});

test('sin borrador la revisión devuelve al mercado', function () {
    actingAs($this->user)->get('/mercado/factura')->assertRedirect('/mercado');
});

test('confirmar crea la compra con sus productos en dólares', function () {
    fakeScanner();
    actingAs($this->user)->post('/mercado/escanear', ['invoice' => UploadedFile::fake()->image('f.jpg')]);

    actingAs($this->user)->post('/mercado/factura', [
        'name' => 'Mercado de agosto',
        'store' => 'Kosmos',
        'date' => '2026-08-28',
        'items' => [
            // 90 Bs a tasa 50 = 1.80 $, ya convertido en la revisión.
            ['name' => 'Harina', 'brand' => 'P.A.N.', 'size' => '1 kg', 'quantity' => 2, 'unit_price_usd' => 1.80],
            ['name' => 'Leche', 'brand' => null, 'size' => '1 L', 'quantity' => 1, 'unit_price_usd' => 2.40],
        ],
    ])->assertRedirect();

    $trip = ShoppingTrip::with('items')->first();
    expect($trip->name)->toBe('Mercado de agosto');
    expect($trip->store)->toBe('Kosmos');
    expect($trip->items)->toHaveCount(2);
    expect($trip->total_usd)->toBe(6.0); // 1.80*2 + 2.40
    expect($trip->receipt_path)->not->toBeNull();
    Storage::disk('public')->assertExists($trip->receipt_path);
});

test('la foto deja de estar en el borrador una vez creada la compra', function () {
    fakeScanner();
    actingAs($this->user)->post('/mercado/escanear', ['invoice' => UploadedFile::fake()->image('f.jpg')]);

    actingAs($this->user)->post('/mercado/factura', [
        'items' => [['name' => 'Harina', 'quantity' => 1, 'unit_price_usd' => 1]],
    ])->assertRedirect()->assertSessionMissing('invoice_draft');

    // La foto sobrevive: ahora pertenece a la compra, no al borrador.
    Storage::disk('public')->assertExists(ShoppingTrip::first()->receipt_path);
});

test('descartar el borrador borra también su foto', function () {
    fakeScanner();
    actingAs($this->user)->post('/mercado/escanear', ['invoice' => UploadedFile::fake()->image('f.jpg')]);
    $path = session('invoice_draft')['receipt_path'];

    actingAs($this->user)->delete('/mercado/factura')
        ->assertRedirect('/mercado')
        ->assertSessionMissing('invoice_draft');

    Storage::disk('public')->assertMissing($path);
});

test('confirmar sin borrador no crea nada', function () {
    actingAs($this->user)->post('/mercado/factura', [
        'items' => [['name' => 'Harina', 'quantity' => 1, 'unit_price_usd' => 1]],
    ])->assertRedirect('/mercado');

    expect(ShoppingTrip::count())->toBe(0);
});

test('una compra escaneada le pasa su factura al gasto', function () {
    fakeScanner();
    actingAs($this->user)->post('/mercado/escanear', ['invoice' => UploadedFile::fake()->image('f.jpg')]);
    actingAs($this->user)->post('/mercado/factura', [
        'items' => [['name' => 'Harina', 'quantity' => 2, 'unit_price_usd' => 1.5]],
    ]);

    $trip = ShoppingTrip::first();

    actingAs($this->user)->post("/mercado/{$trip->id}/terminar", ['as_expense' => true]);

    $expense = $trip->fresh()->expense;
    expect($expense)->not->toBeNull();
    expect($expense->receipt_path)->not->toBeNull();
    // Copia, no el mismo archivo: borrar la compra no puede dejar el gasto
    // sin comprobante.
    expect($expense->receipt_path)->not->toBe($trip->receipt_path);
    Storage::disk('public')->assertExists($expense->receipt_path);
});

test('borrar la compra deja intacto el comprobante del gasto', function () {
    fakeScanner();
    actingAs($this->user)->post('/mercado/escanear', ['invoice' => UploadedFile::fake()->image('f.jpg')]);
    actingAs($this->user)->post('/mercado/factura', [
        'items' => [['name' => 'Harina', 'quantity' => 1, 'unit_price_usd' => 3]],
    ]);

    $trip = ShoppingTrip::first();
    actingAs($this->user)->post("/mercado/{$trip->id}/terminar", ['as_expense' => true]);
    $expenseReceipt = $trip->fresh()->expense->receipt_path;

    actingAs($this->user)->delete("/mercado/{$trip->id}");

    Storage::disk('public')->assertMissing($trip->receipt_path);
    Storage::disk('public')->assertExists($expenseReceipt);
});

test('confirmar exige al menos un producto', function () {
    fakeScanner();
    actingAs($this->user)->post('/mercado/escanear', ['invoice' => UploadedFile::fake()->image('f.jpg')]);

    actingAs($this->user)->post('/mercado/factura', ['items' => []])
        ->assertSessionHasErrors('items');

    expect(ShoppingTrip::count())->toBe(0);
});

test('no se acepta un archivo que no sea imagen', function () {
    fakeScanner();

    actingAs($this->user)
        ->post('/mercado/escanear', ['invoice' => UploadedFile::fake()->create('factura.pdf', 100)])
        ->assertSessionHasErrors('invoice');
});

// --- Normalización de lo que devuelve el modelo ---------------------------

test('las líneas sin nombre se descartan', function () {
    $result = app(InvoiceScanner::class)->normalize([
        'currency' => 'VES',
        'items' => [
            ['name' => 'Harina', 'quantity' => 1, 'unit_price' => 90],
            ['name' => '   ', 'quantity' => 1, 'unit_price' => 10],
            ['quantity' => 1, 'unit_price' => 5],
        ],
    ]);

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['name'])->toBe('Harina');
});

test('si la factura no trae total se usa la suma de los productos', function () {
    $result = app(InvoiceScanner::class)->normalize([
        'currency' => 'VES',
        'items' => [
            ['name' => 'Harina', 'quantity' => 2, 'unit_price' => 90],
            ['name' => 'Leche', 'quantity' => 1, 'unit_price' => 120],
        ],
        'total' => null,
    ]);

    expect($result['total'])->toBe(300.0);
    expect($result['items_total'])->toBe(300.0);
});

test('el total declarado se conserva aunque no cuadre, para poder avisar', function () {
    // Si se corrigiera en silencio, nadie se enteraría de que falta una línea.
    $result = app(InvoiceScanner::class)->normalize([
        'currency' => 'VES',
        'items' => [['name' => 'Harina', 'quantity' => 1, 'unit_price' => 90]],
        'total' => 500,
    ]);

    expect($result['total'])->toBe(500.0);
    expect($result['items_total'])->toBe(90.0);
});

test('una moneda desconocida cae en bolívares', function () {
    $result = app(InvoiceScanner::class)->normalize(['currency' => 'XYZ', 'items' => []]);

    expect($result['currency'])->toBe('VES');
});

test('las cantidades y precios absurdos se sanean', function () {
    $result = app(InvoiceScanner::class)->normalize([
        'items' => [['name' => 'Harina', 'quantity' => 0, 'unit_price' => -5]],
    ]);

    expect($result['items'][0]['quantity'])->toBe(0.01);
    expect($result['items'][0]['unit_price'])->toBe(0.0);
});

// --- Tickets de máquina fiscal --------------------------------------------
// Los casos salen de un ticket real de Supermercados Luxor, donde la línea
// "Nx Bs P" va encima del producto y la cantidad usa punto decimal.

/** Lee una sola línea y devuelve el producto normalizado. */
function normalizedLine(array $item): array
{
    return app(InvoiceScanner::class)->normalize([
        'confidence' => 'alta',
        'items' => [['name' => 'Producto', ...$item]],
    ])['items'][0];
}

test('una línea que ya cuadra con su importe no se toca', function () {
    $item = normalizedLine(['quantity' => 2, 'unit_price' => 1173.15, 'line_total' => 2346.30]);

    expect($item['quantity'])->toBe(2.0);
    expect($item['unit_price'])->toBe(1173.15);
});

test('si se copió el importe como precio unitario, se divide por la cantidad', function () {
    // "2x Bs 1.173,15 / Azucar ... Bs 2.346,30" leído como 2 a 2.346,30.
    $item = normalizedLine(['quantity' => 2, 'unit_price' => 2346.30, 'line_total' => 2346.30]);

    expect($item['quantity'])->toBe(2.0);
    expect($item['unit_price'])->toBe(1173.15);
});

test('un producto a peso con el importe como precio recupera el precio por kilo', function () {
    // "0.52xBs 3.478,73 / Tomate Kg ... Bs 1.808,94".
    $item = normalizedLine(['quantity' => 0.52, 'unit_price' => 1808.94, 'line_total' => 1808.94]);

    expect($item['quantity'])->toBe(0.52);
    expect($item['unit_price'])->toBe(3478.73);
});

test('una cantidad a peso leída con el punto de miles se corrige', function () {
    // "0.385xBs 1.979,70" tomado por 385 kilos: el precio es fiable, la
    // cantidad no.
    $item = normalizedLine(['quantity' => 385, 'unit_price' => 1979.70, 'line_total' => 762.18]);

    expect($item['quantity'])->toBe(0.385);
    expect($item['unit_price'])->toBe(1979.70);
});

test('un precio ilegible se saca del importe', function () {
    $item = normalizedLine(['quantity' => 1, 'unit_price' => 0, 'line_total' => 806.54]);

    expect($item['unit_price'])->toBe(806.54);
});

test('corregir una lectura baja la confianza y lo cuenta en las notas', function () {
    $result = app(InvoiceScanner::class)->normalize([
        'confidence' => 'alta',
        'items' => [
            ['name' => 'Azúcar', 'quantity' => 2, 'unit_price' => 2346.30, 'line_total' => 2346.30],
            ['name' => 'Tomate', 'quantity' => 0.52, 'unit_price' => 1808.94, 'line_total' => 1808.94],
            ['name' => 'Ajo', 'quantity' => 1, 'unit_price' => 806.54, 'line_total' => 806.54],
        ],
    ]);

    expect($result['confidence'])->toBe('media');
    expect($result['notes'])->toContain('2 productos');
    expect($result['items_total'])->toBe(round(2346.30 + 1808.94 + 806.54, 2));
});

test('sin importe de línea se respeta lo leído', function () {
    $item = normalizedLine(['quantity' => 3, 'unit_price' => 100]);

    expect($item['quantity'])->toBe(3.0);
    expect($item['unit_price'])->toBe(100.0);
});

test('se conserva si cada producto paga IVA', function () {
    $result = app(InvoiceScanner::class)->normalize(['items' => [
        ['name' => 'Galletas', 'quantity' => 1, 'unit_price' => 10, 'taxed' => true],
        ['name' => 'Tomate', 'quantity' => 1, 'unit_price' => 10, 'taxed' => false],
        ['name' => 'Pan', 'quantity' => 1, 'unit_price' => 10, 'taxed' => null],
        ['name' => 'Queso', 'quantity' => 1, 'unit_price' => 10, 'taxed' => 'sí'],
    ]]);

    expect(array_column($result['items'], 'taxed'))->toBe([true, false, null, null]);
});

test('una confianza que no reconocemos se trata como media', function () {
    $result = app(InvoiceScanner::class)->normalize(['items' => [], 'confidence' => 'buenísima']);

    expect($result['confidence'])->toBe('media');
});
