<?php

namespace App\Services\Invoices;

use RuntimeException;

/**
 * Quien lee la foto de una factura y devuelve sus datos crudos.
 *
 * Hay dos implementaciones porque el precio manda: Anthropic lee mejor las
 * fotos malas, Gemini tiene capa gratuita. El resto de la app no debe
 * enterarse de cuál está activa.
 */
interface InvoiceReader
{
    /** Nombre corto para mensajes de error y diagnóstico. */
    public function name(): string;

    /** ¿Hay clave para este proveedor? */
    public function isConfigured(): bool;

    /**
     * Lee la imagen y devuelve el JSON del modelo, sin normalizar.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException si el proveedor falla o responde algo ilegible
     */
    public function read(string $absolutePath, string $mediaType): array;
}
