<?php

namespace App\Http\Controllers\Concerns;

use App\Services\ImageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Comprobantes adjuntos a gastos, abonos y aportes.
 *
 * La foto es siempre opcional: registrar el movimiento no puede depender de
 * tener el recibo a mano. Por eso también se puede añadir después, editando
 * un registro que ya existía.
 */
trait HandlesReceipts
{
    /** @var array<string, mixed> */
    private const RULES = [
        // 8 MB: las fotos de iPhone pesan bastante y luego se reducen.
        'receipt' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192'],
        'remove_receipt' => ['nullable', 'boolean'],
    ];

    /** Comprobante de un registro nuevo, o null si no se adjuntó ninguno. */
    protected function storeReceipt(Request $request, ImageService $images): ?string
    {
        $request->validate(self::RULES);

        return $request->hasFile('receipt')
            ? $images->receipt($request->file('receipt'))
            : null;
    }

    /**
     * Pone al día el comprobante de un registro que ya existe.
     *
     * Tres casos: llega una foto nueva (reemplaza y borra la anterior), se
     * pidió quitar la que había, o no se tocó el adjunto y se deja como está.
     * El borrado del archivo viejo va después de guardar: si el guardado
     * fallara, la foto seguiría estando donde la fila la apunta.
     */
    protected function syncReceipt(Request $request, ImageService $images, Model $entry): void
    {
        $request->validate(self::RULES);

        $previous = $entry->receipt_path;

        if ($request->hasFile('receipt')) {
            $entry->update(['receipt_path' => $images->receipt($request->file('receipt'))]);
            $images->delete($previous);

            return;
        }

        if ($request->boolean('remove_receipt') && $previous) {
            $entry->update(['receipt_path' => null]);
            $images->delete($previous);
        }
    }
}
