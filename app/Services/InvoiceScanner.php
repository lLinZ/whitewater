<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\TextBlockParam;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Lee una foto de factura y devuelve sus productos y totales.
 *
 * Un OCR corriente no sirve aquí: las facturas venezolanas mezclan el nombre
 * del producto con códigos, repiten el IVA por línea y traen los montos con el
 * punto de miles y la coma decimal. Un modelo con visión lee el ticket como lo
 * leería una persona, y devuelve el resultado ya con la forma que necesita la
 * app.
 *
 * Nada de lo que sale de aquí se guarda solo: siempre pasa por una pantalla de
 * revisión. Una foto torcida o con reflejo produce lecturas malas, y el dinero
 * del hogar no puede depender de que la foto saliera bien.
 */
class InvoiceScanner
{
    /**
     * Instrucciones de lectura.
     *
     * Lo más importante es el formato de números: en Venezuela "1.234,56" son
     * mil doscientos treinta y cuatro con cincuenta y seis. Interpretarlo al
     * revés multiplicaría cada precio por mil sin que nada parezca roto.
     */
    private const SYSTEM = <<<'PROMPT'
        Lees fotos de facturas y tickets de compra de Venezuela y devuelves sus datos estructurados.

        Formato de números (crítico):
        - El punto separa los miles y la coma los decimales: "1.234,56" son 1234.56, y "12.500,00" son 12500.00.
        - Devuelve siempre los números en formato decimal inglés (punto decimal, sin separador de miles).
        - Si un monto no se lee con seguridad, ponlo en 0 y baja la confianza.

        Qué es un producto:
        - Solo líneas de artículos comprados. NO son productos: subtotal, base imponible, IVA,
          total, vuelto, efectivo, punto de venta, propina, descuentos globales ni datos fiscales.
        - Si una línea trae código y descripción, usa la descripción como nombre.
        - Separa el nombre genérico de la marca y de la presentación cuando se distingan:
          "HARINA PAN 1KG" -> name "Harina", brand "P.A.N.", size "1 kg".
          Si no se distinguen, deja brand y size en null; no los inventes.
        - Normaliza el nombre a minúsculas con la primera letra en mayúscula. No lo dejes TODO EN MAYÚSCULAS.
        - quantity es la cantidad comprada (1 si no aparece). unit_price es el precio por unidad
          y line_total lo que se pagó por esa línea.

        Moneda: "VES" si los montos están en bolívares, "USD" si están en dólares, "EUR" en euros.
        Si la factura muestra ambas, usa la moneda de los montos que estás leyendo.

        Fecha: en formato YYYY-MM-DD. Si no aparece o no se lee, null.

        Confianza: "alta" si la foto es nítida y cuadran los totales; "media" si tuviste que
        interpretar; "baja" si la foto está borrosa, cortada o hay líneas que no pudiste leer.
        En notes explica en una frase corta qué te costó leer, o null si nada.
        PROMPT;

    /** Formatos que acepta la API de visión. */
    private const MEDIA_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** ¿Hay clave configurada? Sin ella la app esconde el escaneo. */
    public function isConfigured(): bool
    {
        return (bool) config('services.anthropic.key');
    }

    /**
     * Lee la factura y devuelve sus datos.
     *
     * @param  string  $absolutePath  ruta a la imagen ya reducida
     * @return array<string, mixed>
     *
     * @throws RuntimeException si no se pudo leer
     */
    public function scan(string $absolutePath): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Falta ANTHROPIC_API_KEY en el .env: el escaneo de facturas está desactivado.');
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mediaType = self::MEDIA_TYPES[$extension] ?? null;

        if ($mediaType === null) {
            throw new RuntimeException('Formato de imagen no soportado para el escaneo.');
        }

        $client = new Client(apiKey: (string) config('services.anthropic.key'));

        try {
            $message = $client->messages->create(
                model: (string) config('services.anthropic.model'),
                maxTokens: 8000,
                system: self::SYSTEM,
                // El esquema obliga a que la respuesta sea JSON válido con esta
                // forma exacta: no hay que parsear texto libre ni rezar.
                outputConfig: OutputConfig::with(
                    // 'medium': la tarea está bien especificada, no hace falta
                    // gastar en razonamiento profundo para leer un ticket.
                    effort: 'medium',
                    format: JSONOutputFormat::with(schema: self::schema()),
                ),
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ImageBlockParam::with(
                            source: Base64ImageSource::with(
                                data: base64_encode((string) file_get_contents($absolutePath)),
                                mediaType: $mediaType,
                            ),
                        ),
                        TextBlockParam::with(text: 'Extrae los productos y los totales de esta factura.'),
                    ],
                ]],
            );
        } catch (Throwable $e) {
            Log::warning('Escaneo de factura falló: '.$e->getMessage());

            throw new RuntimeException('No se pudo leer la factura: '.$e->getMessage(), previous: $e);
        }

        return $this->normalize($this->decode($message->content));
    }

    /**
     * Saca el JSON del primer bloque de texto.
     *
     * @param  array<int, mixed>  $content
     * @return array<string, mixed>
     */
    private function decode(array $content): array
    {
        foreach ($content as $block) {
            if (($block->type ?? null) !== 'text') {
                continue;
            }

            $data = json_decode($block->text, true);

            if (is_array($data)) {
                return $data;
            }
        }

        throw new RuntimeException('La respuesta del lector de facturas no vino en el formato esperado.');
    }

    /**
     * Deja los datos listos para la pantalla de revisión.
     *
     * Se descartan las líneas sin nombre y se recalcula el total a partir de
     * los productos cuando la factura no lo trae: la revisión necesita un
     * número con el que comparar.
     *
     * Es pública para poder probarla con respuestas malas sin gastar una
     * llamada real a la API.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        $items = collect($data['items'] ?? [])
            ->filter(fn ($item) => trim((string) ($item['name'] ?? '')) !== '')
            ->map(fn ($item) => [
                'name' => trim((string) $item['name']),
                'brand' => $this->blankToNull($item['brand'] ?? null),
                'size' => $this->blankToNull($item['size'] ?? null),
                // Los ceros van con decimal: max() devuelve el argumento tal
                // cual, y un 0 entero rompería el tipo del campo.
                'quantity' => max(0.01, (float) ($item['quantity'] ?? 1)),
                'unit_price' => max(0.0, (float) ($item['unit_price'] ?? 0)),
            ])
            ->values()
            ->all();

        $itemsTotal = array_sum(array_map(
            fn ($item) => $item['unit_price'] * $item['quantity'],
            $items
        ));

        return [
            'store' => $this->blankToNull($data['store'] ?? null),
            'date' => $this->blankToNull($data['date'] ?? null),
            'currency' => in_array($data['currency'] ?? null, ['VES', 'USD', 'EUR'], true)
                ? $data['currency']
                : 'VES',
            'items' => $items,
            'subtotal' => $this->toFloatOrNull($data['subtotal'] ?? null),
            'tax' => $this->toFloatOrNull($data['tax'] ?? null),
            'total' => $this->toFloatOrNull($data['total'] ?? null) ?? round($itemsTotal, 2),
            'items_total' => round($itemsTotal, 2),
            'confidence' => in_array($data['confidence'] ?? null, ['alta', 'media', 'baja'], true)
                ? $data['confidence']
                : 'media',
            'notes' => $this->blankToNull($data['notes'] ?? null),
        ];
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /**
     * Esquema de la respuesta.
     *
     * Los campos opcionales se declaran como `["string", "null"]` en vez de
     * omitirlos de `required`: la salida estructurada exige que todas las
     * propiedades estén en `required`.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['store', 'date', 'currency', 'items', 'subtotal', 'tax', 'total', 'confidence', 'notes'],
            'properties' => [
                'store' => ['type' => ['string', 'null'], 'description' => 'Nombre del comercio'],
                'date' => ['type' => ['string', 'null'], 'description' => 'Fecha de la factura, YYYY-MM-DD'],
                'currency' => ['type' => 'string', 'enum' => ['VES', 'USD', 'EUR']],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'brand', 'size', 'quantity', 'unit_price'],
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Producto, sin marca ni presentación'],
                            'brand' => $nullableString,
                            'size' => $nullableString,
                            'quantity' => ['type' => 'number'],
                            'unit_price' => ['type' => 'number', 'description' => 'Precio por unidad, en la moneda de la factura'],
                        ],
                    ],
                ],
                'subtotal' => $nullableNumber,
                'tax' => $nullableNumber,
                'total' => $nullableNumber,
                'confidence' => ['type' => 'string', 'enum' => ['alta', 'media', 'baja']],
                'notes' => $nullableString,
            ],
        ];
    }
}
