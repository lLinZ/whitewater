<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Comprobante adjunto (foto del recibo del banco, ticket del súper…).
 *
 * El front nunca ve la ruta cruda: recibe `receipt_url` ya lista para un
 * <img>, o null cuando el registro no tiene comprobante.
 */
trait HasReceipt
{
    public function getReceiptUrlAttribute(): ?string
    {
        return $this->receipt_path ? Storage::disk('public')->url($this->receipt_path) : null;
    }

    public function initializeHasReceipt(): void
    {
        $this->append('receipt_url');
        $this->makeHidden('receipt_path');
    }
}
