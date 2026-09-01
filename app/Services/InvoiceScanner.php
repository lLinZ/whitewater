<?php

namespace App\Services;

use App\Services\Invoices\AnthropicReader;
use App\Services\Invoices\GeminiReader;
use App\Services\Invoices\InvoiceReader;
use RuntimeException;

/**
 * Lee una foto de factura y devuelve sus productos y totales.
 *
 * Un OCR corriente no sirve aquí: las facturas venezolanas mezclan el nombre
 * del producto con códigos, repiten el IVA por línea y traen los montos con el
 * punto de miles y la coma decimal. Un modelo con visión lee el ticket como lo
 * leería una persona, y devuelve el resultado ya con la forma que necesita la
 * app.
 *
 * Detrás hay dos proveedores intercambiables (ver `services.invoice_scanner`).
 * Esta clase elige uno y deja el resultado siempre con la misma forma, para
 * que cambiar de proveedor sea una línea del .env y no un cambio de código.
 *
 * Nada de lo que sale de aquí se guarda solo: siempre pasa por una pantalla de
 * revisión. Una foto torcida o con reflejo produce lecturas malas, y el dinero
 * del hogar no puede depender de que la foto saliera bien.
 */
class InvoiceScanner
{
    /** Formatos que aceptan las APIs de visión. */
    private const MEDIA_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** Orden de preferencia cuando el driver es 'auto'. */
    private const DRIVERS = [
        'anthropic' => AnthropicReader::class,
        'gemini' => GeminiReader::class,
    ];

    /** ¿Hay algún proveedor con clave? Sin ninguno la app esconde el escaneo. */
    public function isConfigured(): bool
    {
        return $this->reader() !== null;
    }

    /** Qué proveedor está leyendo ahora mismo, para diagnóstico. */
    public function providerName(): ?string
    {
        return $this->reader()?->name();
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
        $reader = $this->reader();

        if ($reader === null) {
            throw new RuntimeException('No hay ningún lector de facturas configurado (ANTHROPIC_API_KEY o GEMINI_API_KEY).');
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mediaType = self::MEDIA_TYPES[$extension] ?? null;

        if ($mediaType === null) {
            throw new RuntimeException('Formato de imagen no soportado para el escaneo.');
        }

        return $this->normalize($reader->read($absolutePath, $mediaType));
    }

    /**
     * El lector activo, o null si ninguno tiene clave.
     *
     * Con driver 'auto' gana el primero configurado en el orden de DRIVERS:
     * si algún día hay clave de los dos, se usa el que lee mejor.
     */
    private function reader(): ?InvoiceReader
    {
        $configured = (string) config('services.invoice_scanner.driver', 'auto');

        $candidates = isset(self::DRIVERS[$configured])
            ? [self::DRIVERS[$configured]]
            : array_values(self::DRIVERS);

        foreach ($candidates as $class) {
            /** @var InvoiceReader $reader */
            $reader = app($class);

            if ($reader->isConfigured()) {
                return $reader;
            }
        }

        return null;
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
}
