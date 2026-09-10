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
        $corrected = 0;

        $items = collect($data['items'] ?? [])
            ->filter(fn ($item) => trim((string) ($item['name'] ?? '')) !== '')
            ->map(function ($item) use (&$corrected) {
                // Los ceros van con decimal: max() devuelve el argumento tal
                // cual, y un 0 entero rompería el tipo del campo.
                $quantity = max(0.01, (float) ($item['quantity'] ?? 1));
                $unitPrice = max(0.0, (float) ($item['unit_price'] ?? 0));

                [$quantity, $unitPrice, $fixed] = $this->reconcile(
                    $quantity, $unitPrice, $this->toFloatOrNull($item['line_total'] ?? null)
                );
                $corrected += (int) $fixed;

                return [
                    'name' => trim((string) $item['name']),
                    'brand' => $this->blankToNull($item['brand'] ?? null),
                    'size' => $this->blankToNull($item['size'] ?? null),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'taxed' => is_bool($item['taxed'] ?? null) ? $item['taxed'] : null,
                ];
            })
            ->values()
            ->all();

        $confidence = in_array($data['confidence'] ?? null, ['alta', 'media', 'baja'], true)
            ? $data['confidence']
            : 'media';
        $notes = $this->blankToNull($data['notes'] ?? null);

        if ($corrected > 0) {
            // Si hubo que arreglar la lectura, "alta" ya no es verdad.
            $confidence = $confidence === 'alta' ? 'media' : $confidence;
            $notes = trim(($notes ?? '').' '.($corrected === 1
                ? 'Se ajustó 1 producto para que cuadre con su importe.'
                : "Se ajustaron {$corrected} productos para que cuadren con su importe."));
        }

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
            'confidence' => $confidence,
            'notes' => $notes,
        ];
    }

    /**
     * Hace cuadrar cantidad × precio con el importe impreso de la línea.
     *
     * El importe de la derecha es el número más grande y más legible de cada
     * línea, y la suma de todos es el subtotal: es el dato en el que más se
     * puede confiar. Cuando no cuadra, lo que falla es la cantidad o el
     * precio unitario, y cuál de los dos se deduce de cómo falla:
     *
     * - Si el precio unitario es igual al importe, el modelo copió el importe
     *   como precio ("2x Bs 1.173,15" leído como 2 a 2.346,30): se divide.
     * - Si es un número distinto, ese precio salió de la línea "Nx Bs P" y es
     *   fiable; lo que se leyó mal es la cantidad (0.385 kg tomado por 385).
     *
     * @return array{0: float, 1: float, 2: bool} cantidad, precio, si se tocó
     */
    private function reconcile(float $quantity, float $unitPrice, ?float $lineTotal): array
    {
        if ($lineTotal === null || $lineTotal <= 0) {
            return [$quantity, $unitPrice, false];
        }

        $tolerance = max(0.02, $lineTotal * 0.01);

        if (abs($quantity * $unitPrice - $lineTotal) <= $tolerance) {
            return [$quantity, $unitPrice, false];
        }

        if ($unitPrice <= 0 || abs($unitPrice - $lineTotal) <= $tolerance) {
            return [$quantity, round($lineTotal / $quantity, 2), true];
        }

        return [max(0.01, round($lineTotal / $unitPrice, 3)), $unitPrice, true];
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
