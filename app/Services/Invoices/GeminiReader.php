<?php

namespace App\Services\Invoices;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lee la factura con Gemini.
 *
 * Google tiene capa gratuita, así que sirve para empezar sin pagar. Acierta
 * menos que Claude en fotos torcidas o con reflejo, cosa que se nota sobre
 * todo en los precios; por eso la pantalla de revisión existe.
 *
 * Va por HTTP directo en vez de por un SDK: Google no publica uno oficial para
 * PHP, y la llamada es una sola petición JSON.
 */
class GeminiReader implements InvoiceReader
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function name(): string
    {
        return 'Gemini';
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.gemini.key');
    }

    public function read(string $absolutePath, string $mediaType): array
    {
        $model = (string) config('services.gemini.model');

        $response = Http::withHeaders([
            // En cabecera y no como ?key=: así la clave no queda escrita en
            // los logs de acceso de ningún proxy por el que pase.
            'x-goog-api-key' => (string) config('services.gemini.key'),
        ])
            // Leer una factura tarda entre 5 y 20 segundos.
            ->timeout(90)
            ->post(sprintf(self::ENDPOINT, $model), [
                'system_instruction' => [
                    'parts' => [['text' => InvoiceSchema::SYSTEM]],
                ],
                'contents' => [[
                    'parts' => [
                        ['inline_data' => [
                            'mime_type' => $mediaType,
                            'data' => base64_encode((string) file_get_contents($absolutePath)),
                        ]],
                        ['text' => InvoiceSchema::INSTRUCTION],
                    ],
                ]],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                    'response_schema' => InvoiceSchema::forGemini(),
                    // Leer un ticket no es una tarea creativa: se quiere el
                    // mismo resultado cada vez que se lee la misma foto.
                    'temperature' => 0,
                ],
            ]);

        if ($response->failed()) {
            $reason = $response->json('error.message') ?? 'HTTP '.$response->status();
            Log::warning('Escaneo de factura (Gemini) falló: '.$reason);

            throw new RuntimeException($this->explain($response->status(), $reason));
        }

        $body = $response->json();

        // Una respuesta cortada o rechazada llega con 200 y sin texto: hay que
        // mirar el motivo o el fallo se confundiría con una factura ilegible.
        $finish = $body['candidates'][0]['finishReason'] ?? null;

        if ($finish !== null && ! in_array($finish, ['STOP', 'MAX_TOKENS'], true)) {
            throw new RuntimeException("Gemini no pudo procesar la imagen (motivo: {$finish}).");
        }

        // Los modelos que razonan pueden devolver el razonamiento como partes
        // aparte (thought: true) antes de la respuesta: solo cuenta el resto.
        $text = collect($body['candidates'][0]['content']['parts'] ?? [])
            ->reject(fn ($part) => $part['thought'] ?? false)
            ->pluck('text')
            ->implode('');
        $data = $text !== '' ? json_decode($text, true) : null;

        if (! is_array($data)) {
            Log::warning('Respuesta de Gemini ilegible: '.json_encode($body));

            throw new RuntimeException('La respuesta del lector de facturas no vino en el formato esperado.');
        }

        return $data;
    }

    /** Traduce los fallos habituales a algo accionable. */
    private function explain(int $status, string $reason): string
    {
        return match ($status) {
            // El mensaje de Google puede ocupar media pantalla; el completo
            // ya quedó en el log.
            400 => 'Gemini rechazó la petición: '.Str::limit(rtrim($reason, '.'), 160).'. El detalle completo está en storage/logs/laravel.log.',
            401, 403 => 'La clave de Gemini no es válida o no tiene permiso. Revisa GEMINI_API_KEY.',
            404 => 'Ese modelo de Gemini no existe. Cambia GEMINI_MODEL en el .env por uno disponible en tu cuenta.',
            429 => 'Se agotó la cuota gratuita de Gemini por ahora. Prueba de nuevo en un rato.',
            default => "No se pudo leer la factura: {$reason}",
        };
    }
}
