<?php

namespace App\Services\Invoices;

/**
 * Qué se le pide al modelo y con qué forma debe responder.
 *
 * Lo comparten los dos lectores: cambiar de proveedor no debe cambiar lo que
 * se extrae ni cómo se interpreta un ticket. Lo que no comparten es el
 * dialecto del esquema: Anthropic acepta JSON Schema y Gemini un subconjunto
 * de OpenAPI (ver forGemini()), así que el esquema se escribe una vez y se
 * traduce.
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
        - En los montos, el punto separa los miles y la coma los decimales: "1.234,56" son 1234.56,
          y "12.500,00" son 12500.00.
        - Excepción: en las cantidades de las líneas de multiplicación (ver abajo) la máquina fiscal
          suele usar el punto como decimal. "0.385xBs 1.979,70" son 0.385 kilos a 1979.70 cada uno,
          no 385. Una cantidad que empieza por "0." siempre es decimal.
        - Devuelve siempre los números en formato decimal inglés (punto decimal, sin separador de miles).
        - Si un monto no se lee con seguridad, ponlo en 0 y baja la confianza.

        Tickets de máquina fiscal (SENIAT), el formato más común:
        - Cada producto ocupa una línea: la descripción a la izquierda y, a la derecha, el IMPORTE
          de la línea (cantidad × precio unitario). Ese importe va en line_total.
        - Si se compró más de una unidad, o un producto a peso, la máquina imprime una línea extra
          con la cantidad y el precio unitario: "2x Bs 1.173,15" o "0.52xBs 3.478,73".
          Esa línea va ENCIMA del producto al que pertenece: se aplica a la línea de producto
          SIGUIENTE, no a la anterior. Compruébalo multiplicando: 2 × 1.173,15 = 2.346,30 tiene
          que coincidir con el importe de la línea que viene debajo.
        - Sin línea de multiplicación, la cantidad es 1 y el precio unitario es el importe.
        - "(E)" al final de la descripción es exento de IVA: taxed false. "(G)" (alícuota general)
          o "(R)" (reducida) son gravados: taxed true. Si el ticket no lo marca, taxed null.
        - No son productos: SUBTTL, EXENTO, BI (base imponible), IVA, OTROS PAGOS, EFECTIVO,
          TARJETA, PAGO MÓVIL, VUELTO, TOTAL ni "Cantidad de artículos".

        Qué es un producto (cualquier factura):
        - Solo líneas de artículos comprados. NO son productos: subtotal, base imponible, IVA,
          total, vuelto, formas de pago, propina, descuentos globales ni datos fiscales.
        - Si una línea trae código y descripción, usa la descripción como nombre.
        - Separa el nombre genérico de la marca y de la presentación cuando se distingan:
          "HARINA PAN 1KG" -> name "Harina", brand "P.A.N.", size "1 kg".
          Si no se distinguen, deja brand y size en null; no los inventes.
        - Desabrevia solo lo evidente ("Gallet" -> "Galletas", "Mant" -> "Mantequilla").
        - Normaliza el nombre a minúsculas con la primera letra en mayúscula. No lo dejes TODO EN MAYÚSCULAS.
        - quantity es la cantidad comprada (1 si no aparece). unit_price es el precio por unidad.

        Moneda: "VES" si los montos están en bolívares, "USD" si están en dólares, "EUR" en euros.
        Si la factura muestra ambas, usa la moneda de los montos que estás leyendo.

        Fecha: en formato YYYY-MM-DD. En Venezuela la fecha se escribe día-mes-año: "08-09-2026" es
        el 8 de septiembre. Si no aparece o no se lee, null.

        Totales: subtotal es la suma antes de IVA (SUBTTL), tax el IVA y total lo que se pagó.

        Confianza: "alta" si la foto es nítida y los importes suman el subtotal; "media" si tuviste
        que interpretar; "baja" si la foto está borrosa, cortada o hay líneas que no pudiste leer.
        En notes explica en una frase corta qué te costó leer, o null si nada.
        PROMPT;

    /** Lo que se le pide en el mensaje de usuario, junto a la foto. */
    public const INSTRUCTION = 'Extrae los productos y los totales de esta factura.';

    /**
     * Forma exacta de la respuesta, en JSON Schema.
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
                        'required' => ['name', 'brand', 'size', 'quantity', 'unit_price', 'line_total', 'taxed'],
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Producto, sin marca ni presentación'],
                            'brand' => $nullableString,
                            'size' => $nullableString,
                            'quantity' => ['type' => 'number'],
                            'unit_price' => ['type' => 'number', 'description' => 'Precio por unidad, en la moneda de la factura'],
                            'line_total' => ['type' => 'number', 'description' => 'Importe de la línea, a la derecha: cantidad × precio unitario'],
                            'taxed' => ['type' => ['boolean', 'null'], 'description' => 'true si paga IVA (G/R), false si es exento (E)'],
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

    /**
     * El mismo esquema en el dialecto de `response_schema` de Gemini.
     *
     * Gemini no lee JSON Schema sino un subconjunto de OpenAPI: rechaza la
     * petición entera si ve `additionalProperties` o un tipo en forma de
     * lista. Aquí cada tipo pasa a ser uno solo en mayúsculas, lo nulable se
     * marca con `nullable`, y se fija el orden de las propiedades para que
     * el modelo las escriba en el orden en que se leen en el ticket.
     *
     * @return array<string, mixed>
     */
    public static function forGemini(): array
    {
        return self::toOpenApi(self::schema());
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function toOpenApi(array $node): array
    {
        $out = [];
        $types = (array) ($node['type'] ?? []);
        $concrete = array_values(array_diff($types, ['null']));

        if ($concrete !== []) {
            $out['type'] = strtoupper($concrete[0]);
        }

        if (in_array('null', $types, true)) {
            $out['nullable'] = true;
        }

        if (isset($node['description'])) {
            $out['description'] = $node['description'];
        }

        if (isset($node['enum'])) {
            $out['format'] = 'enum';
            $out['enum'] = $node['enum'];
        }

        if (isset($node['properties'])) {
            $out['properties'] = array_map(self::toOpenApi(...), $node['properties']);
            $out['propertyOrdering'] = array_keys($node['properties']);
        }

        if (isset($node['required'])) {
            $out['required'] = $node['required'];
        }

        if (isset($node['items'])) {
            $out['items'] = self::toOpenApi($node['items']);
        }

        return $out;
    }
}
