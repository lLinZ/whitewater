<?php

namespace App\Services\Invoices;

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
 * Lee la factura con Claude.
 *
 * Es el que mejor aguanta las fotos malas (torcidas, con reflejo, arrugadas),
 * pero se paga por escaneo.
 */
class AnthropicReader implements InvoiceReader
{
    public function name(): string
    {
        return 'Anthropic';
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.anthropic.key');
    }

    public function read(string $absolutePath, string $mediaType): array
    {
        $client = new Client(apiKey: (string) config('services.anthropic.key'));

        try {
            $message = $client->messages->create(
                model: (string) config('services.anthropic.model'),
                maxTokens: 8000,
                system: InvoiceSchema::SYSTEM,
                // El esquema obliga a que la respuesta sea JSON válido con esta
                // forma exacta: no hay que parsear texto libre ni rezar.
                outputConfig: OutputConfig::with(
                    // 'medium': la tarea está bien especificada, no hace falta
                    // gastar en razonamiento profundo para leer un ticket.
                    effort: 'medium',
                    format: JSONOutputFormat::with(schema: InvoiceSchema::schema()),
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
                        TextBlockParam::with(text: InvoiceSchema::INSTRUCTION),
                    ],
                ]],
            );
        } catch (Throwable $e) {
            Log::warning('Escaneo de factura (Anthropic) falló: '.$e->getMessage());

            throw new RuntimeException('No se pudo leer la factura: '.$e->getMessage(), previous: $e);
        }

        foreach ($message->content as $block) {
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
}
