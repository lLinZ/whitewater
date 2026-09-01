<?php

namespace App\Services\Invoices;

/**
 * Qué se le pide al modelo y con qué forma debe responder.
 *
 * Lo comparten los dos lectores: cambiar de proveedor no debe cambiar lo que
 * se extrae ni cómo se interpreta un ticket. Anthropic y Gemini aceptan el
 * mismo dialecto de JSON Schema (tipos nulables como `["string","null"]`,
 * `enum` y `additionalProperties`), así que no hace falta un esquema por cabeza.
 */
class InvoiceSchema
{
    /**
     * Instrucciones de lectura.
     *
     * Lo más importante es el formato de números: en Venezuela "1.234,56" son
     * mil doscientos treinta y cuatro con cincuenta y seis. Interpretarlo al
     * revés multiplicaría cada precio por mil sin que nada parezca roto.
     */
    public const SYSTEM = <<<'PROMPT'
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
        - quantity es la cantidad comprada (1 si no aparece). unit_price es el precio por unidad.

        Moneda: "VES" si los montos están en bolívares, "USD" si están en dólares, "EUR" en euros.
        Si la factura muestra ambas, usa la moneda de los montos que estás leyendo.

        Fecha: en formato YYYY-MM-DD. Si no aparece o no se lee, null.

        Confianza: "alta" si la foto es nítida y cuadran los totales; "media" si tuviste que
        interpretar; "baja" si la foto está borrosa, cortada o hay líneas que no pudiste leer.
        En notes explica en una frase corta qué te costó leer, o null si nada.
        PROMPT;

    /** Lo que se le pide en el mensaje de usuario, junto a la foto. */
    public const INSTRUCTION = 'Extrae los productos y los totales de esta factura.';

    /**
     * Forma exacta de la respuesta.
     *
     * Los campos opcionales se declaran como `["string", "null"]` en vez de
     * omitirlos de `required`: la salida estructurada exige que todas las
     * propiedades estén listadas.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
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
