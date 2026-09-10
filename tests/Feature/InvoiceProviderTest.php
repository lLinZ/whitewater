<?php

use App\Services\InvoiceScanner;
use App\Services\Invoices\GeminiReader;
use App\Services\Invoices\InvoiceSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.anthropic.key' => null,
        'services.gemini.key' => null,
        'services.invoice_scanner.driver' => 'auto',
    ]);
});

// --- Elección de proveedor ------------------------------------------------

test('sin ninguna clave no hay lector', function () {
    expect(app(InvoiceScanner::class)->isConfigured())->toBeFalse();
    expect(app(InvoiceScanner::class)->providerName())->toBeNull();
});

test('con solo la clave de Gemini se usa Gemini', function () {
    config(['services.gemini.key' => 'AIza-de-mentira']);

    expect(app(InvoiceScanner::class)->isConfigured())->toBeTrue();
    expect(app(InvoiceScanner::class)->providerName())->toBe('Gemini');
});

test('con solo la clave de Anthropic se usa Anthropic', function () {
    config(['services.anthropic.key' => 'sk-ant-de-mentira']);

    expect(app(InvoiceScanner::class)->providerName())->toBe('Anthropic');
});

test('con las dos claves gana Anthropic, que lee mejor', function () {
    config([
        'services.anthropic.key' => 'sk-ant-de-mentira',
        'services.gemini.key' => 'AIza-de-mentira',
    ]);

    expect(app(InvoiceScanner::class)->providerName())->toBe('Anthropic');
});

test('el driver del .env manda sobre el orden por defecto', function () {
    config([
        'services.anthropic.key' => 'sk-ant-de-mentira',
        'services.gemini.key' => 'AIza-de-mentira',
        'services.invoice_scanner.driver' => 'gemini',
    ]);

    expect(app(InvoiceScanner::class)->providerName())->toBe('Gemini');
});

test('forzar un proveedor sin clave deja el escaneo apagado', function () {
    // Pedir gemini teniendo solo la clave de Anthropic no debe colarse al otro.
    config([
        'services.anthropic.key' => 'sk-ant-de-mentira',
        'services.invoice_scanner.driver' => 'gemini',
    ]);

    expect(app(InvoiceScanner::class)->isConfigured())->toBeFalse();
});

// --- Lector de Gemini -----------------------------------------------------

/** Respuesta con la forma que devuelve generateContent. */
function geminiReply(array $invoice): array
{
    return [
        'candidates' => [[
            'finishReason' => 'STOP',
            'content' => ['parts' => [['text' => json_encode($invoice)]]],
        ]],
    ];
}

test('Gemini manda la clave en cabecera, nunca en la URL', function () {
    // En la query quedaría escrita en los logs de cualquier proxy.
    config(['services.gemini.key' => 'AIza-secreta']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiReply(['items' => []]))]);

    app(GeminiReader::class)->read(__DIR__.'/../fixtures/factura.jpg', 'image/jpeg');

    Http::assertSent(function ($request) {
        expect($request->url())->not->toContain('AIza-secreta');

        return $request->header('x-goog-api-key')[0] === 'AIza-secreta';
    });
});

test('Gemini recibe la foto, el esquema y temperatura cero', function () {
    config(['services.gemini.key' => 'AIza', 'services.gemini.model' => 'gemini-3.6-flash']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiReply(['items' => []]))]);

    app(GeminiReader::class)->read(__DIR__.'/../fixtures/factura.jpg', 'image/jpeg');

    Http::assertSent(function ($request) {
        $body = $request->data();

        expect($request->url())->toContain('gemini-3.6-flash:generateContent');
        expect($body['contents'][0]['parts'][0]['inline_data']['mime_type'])->toBe('image/jpeg');
        expect($body['contents'][0]['parts'][0]['inline_data']['data'])->not->toBeEmpty();
        expect($body['generationConfig']['response_mime_type'])->toBe('application/json');
        expect($body['generationConfig']['response_schema'])->toBe(InvoiceSchema::forGemini());
        // Leer un ticket no es creativo: la misma foto debe dar lo mismo.
        expect($body['generationConfig']['temperature'])->toBe(0);

        return true;
    });
});

/**
 * Recorre el esquema y devuelve lo que Gemini rechazaría.
 *
 * Son las reglas de `Schema` en la API de Gemini: un solo tipo por nodo, de
 * una lista cerrada, y solo ciertas claves. Cualquier otra cosa (como
 * `additionalProperties` o `"type": ["string", "null"]`) tumba la petición
 * entera con un 400, y eso es justo lo que pasó en producción.
 *
 * @return list<string>
 */
function geminiSchemaProblems(array $node, string $path = '$'): array
{
    $allowed = ['type', 'format', 'description', 'nullable', 'enum', 'properties', 'required', 'items', 'propertyOrdering'];
    $types = ['STRING', 'NUMBER', 'INTEGER', 'BOOLEAN', 'ARRAY', 'OBJECT'];
    $problems = [];

    foreach (array_diff(array_keys($node), $allowed) as $key) {
        $problems[] = "{$path}: clave no admitida '{$key}'";
    }

    if (! is_string($node['type'] ?? null) || ! in_array($node['type'], $types, true)) {
        $problems[] = "{$path}: tipo no admitido ".json_encode($node['type'] ?? null);
    }

    foreach ($node['properties'] ?? [] as $name => $child) {
        $problems = [...$problems, ...geminiSchemaProblems($child, "{$path}.{$name}")];
    }

    if (isset($node['properties'])) {
        if (($node['propertyOrdering'] ?? null) !== array_keys($node['properties'])) {
            $problems[] = "{$path}: propertyOrdering no coincide con las propiedades";
        }
        foreach (array_diff($node['required'] ?? [], array_keys($node['properties'])) as $missing) {
            $problems[] = "{$path}: required nombra '{$missing}', que no existe";
        }
    }

    if (isset($node['items'])) {
        $problems = [...$problems, ...geminiSchemaProblems($node['items'], "{$path}[]")];
    }

    return $problems;
}

test('el esquema que recibe Gemini respeta su dialecto', function () {
    expect(geminiSchemaProblems(InvoiceSchema::forGemini()))->toBe([]);
});

test('el esquema de JSON Schema tal cual no le serviría a Gemini', function () {
    // Prueba de la prueba: si el validador no viera nada en el esquema
    // original, no estaría comprobando lo que falló en producción.
    expect(geminiSchemaProblems(InvoiceSchema::schema()))
        ->toContain("$: clave no admitida 'additionalProperties'")
        ->toContain('$.store: tipo no admitido ["string","null"]');
});

test('la traducción para Gemini conserva qué campos admiten null', function () {
    $schema = InvoiceSchema::forGemini();
    $item = $schema['properties']['items']['items'];

    expect($schema['properties']['store'])->toMatchArray(['type' => 'STRING', 'nullable' => true]);
    expect($schema['properties']['total'])->toMatchArray(['type' => 'NUMBER', 'nullable' => true]);
    expect($schema['properties']['currency'])->not->toHaveKey('nullable');
    expect($schema['properties']['currency']['enum'])->toBe(['VES', 'USD', 'EUR']);
    expect($item['properties']['taxed'])->toMatchArray(['type' => 'BOOLEAN', 'nullable' => true]);
    expect($item['required'])->toContain('line_total');
});

test('un 400 de Gemini se resume en vez de llenar la pantalla', function () {
    config(['services.gemini.key' => 'AIza']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        ['error' => ['message' => str_repeat('Invalid JSON payload received. ', 40)]], 400
    )]);

    try {
        app(GeminiReader::class)->read(__DIR__.'/../fixtures/factura.jpg', 'image/jpeg');
        $this->fail('Debió lanzar una excepción');
    } catch (RuntimeException $e) {
        expect(mb_strlen($e->getMessage()))->toBeLessThan(300);
        expect($e->getMessage())->toContain('laravel.log');
        // Un esquema rechazado no es culpa del modelo: no mandar a cambiarlo.
        expect($e->getMessage())->not->toContain('GEMINI_MODEL');
    }
});

test('el razonamiento del modelo no se confunde con la respuesta', function () {
    config(['services.gemini.key' => 'AIza']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [[
            'finishReason' => 'STOP',
            'content' => ['parts' => [
                ['text' => 'Primero leo el encabezado...', 'thought' => true],
                ['text' => json_encode(['store' => 'Luxor', 'items' => []])],
            ]],
        ]],
    ])]);

    $data = app(GeminiReader::class)->read(__DIR__.'/../fixtures/factura.jpg', 'image/jpeg');

    expect($data['store'])->toBe('Luxor');
});

test('Gemini devuelve los datos ya decodificados', function () {
    config(['services.gemini.key' => 'AIza']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiReply([
        'store' => 'Kosmos',
        'currency' => 'VES',
        'items' => [['name' => 'Harina', 'brand' => null, 'size' => '1 kg', 'quantity' => 2, 'unit_price' => 90]],
    ]))]);

    $data = app(GeminiReader::class)->read(__DIR__.'/../fixtures/factura.jpg', 'image/jpeg');

    expect($data['store'])->toBe('Kosmos');
    expect($data['items'][0]['name'])->toBe('Harina');
});

test('la cuota agotada se explica en vez de soltar un error crudo', function () {
    config(['services.gemini.key' => 'AIza']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);

    expect(fn () => app(GeminiReader::class)->read(__DIR__.'/../fixtures/factura.jpg', 'image/jpeg'))
        ->toThrow(RuntimeException::class, 'cuota gratuita');
});

test('un modelo inexistente dice qué variable hay que cambiar', function () {
    config(['services.gemini.key' => 'AIza']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'not found']], 404)]);

    expect(fn () => app(GeminiReader::class)->read(__DIR__.'/../fixtures/factura.jpg', 'image/jpeg'))
        ->toThrow(RuntimeException::class, 'GEMINI_MODEL');
});

test('una respuesta bloqueada no se confunde con una factura ilegible', function () {
    // finishReason distinto de STOP llega con HTTP 200 y sin texto.
    config(['services.gemini.key' => 'AIza']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['finishReason' => 'SAFETY', 'content' => ['parts' => []]]],
    ])]);

    expect(fn () => app(GeminiReader::class)->read(__DIR__.'/../fixtures/factura.jpg', 'image/jpeg'))
        ->toThrow(RuntimeException::class, 'SAFETY');
});

test('una respuesta sin JSON válido se reporta como formato inesperado', function () {
    config(['services.gemini.key' => 'AIza']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'lo siento, no puedo']]]]],
    ])]);

    expect(fn () => app(GeminiReader::class)->read(__DIR__.'/../fixtures/factura.jpg', 'image/jpeg'))
        ->toThrow(RuntimeException::class, 'formato esperado');
});

test('un formato de imagen que la API no acepta se corta antes de gastar la cuota', function () {
    config(['services.gemini.key' => 'AIza']);
    Http::fake();

    expect(fn () => app(InvoiceScanner::class)->scan(__DIR__.'/../fixtures/factura.bmp'))
        ->toThrow(RuntimeException::class, 'no soportado');

    Http::assertNothingSent();
});
