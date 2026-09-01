<?php

namespace App\Http\Controllers;

use App\Models\ShoppingTrip;
use App\Services\ExchangeRateService;
use App\Services\ImageService;
use App\Services\InvoiceScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use RuntimeException;

/**
 * Crear una compra a partir de la foto de la factura.
 *
 * El escaneo nunca guarda directamente: deja un borrador en la sesión y manda
 * a una pantalla de revisión. Ahí se elige la tasa, se corrigen los precios
 * que el modelo leyó mal y recién entonces se crea la compra. Una foto con
 * reflejo no puede acabar en el historial de gastos sin que nadie la mire.
 */
class InvoiceScanController extends Controller
{
    /** Dónde vive el borrador entre el escaneo y la confirmación. */
    private const DRAFT = 'invoice_draft';

    /** Lee la foto y deja el borrador listo para revisar. */
    public function scan(Request $request, ImageService $images, InvoiceScanner $scanner)
    {
        abort_unless($scanner->isConfigured(), 404);

        $request->validate([
            'invoice' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192'],
        ]);

        // Se reduce antes de mandarla: una foto de iPhone de 4 MB no aporta
        // nada frente a una de 1600px, y se paga por píxel.
        $path = $images->receipt($request->file('invoice'));

        try {
            $data = $scanner->scan(Storage::disk('public')->path($path));
        } catch (RuntimeException $e) {
            $images->delete($path);

            return back()->with('error', $e->getMessage());
        }

        // Un borrador nuevo reemplaza al anterior; el archivo del viejo se
        // borra o se quedaría huérfano en el disco.
        $this->discardDraft($request, $images);

        $request->session()->put(self::DRAFT, [
            'data' => $data,
            'receipt_path' => $path,
        ]);

        return redirect()->route('market.invoice.review');
    }

    /** Pantalla de revisión del borrador. */
    public function review(Request $request, ExchangeRateService $rates)
    {
        $draft = $request->session()->get(self::DRAFT);

        if (! $draft) {
            return redirect()->route('market.index');
        }

        $rate = $rates->latest();

        return Inertia::render('Market/Invoice', [
            'invoice' => $draft['data'],
            'receiptUrl' => Storage::disk('public')->url($draft['receipt_path']),
            'rates' => [
                'bcv_usd' => $rate?->bcv_usd !== null ? (float) $rate->bcv_usd : null,
                'parallel_usd' => $rate?->parallel_usd !== null ? (float) $rate->parallel_usd : null,
                'bcv_eur' => $rate?->bcv_eur !== null ? (float) $rate->bcv_eur : null,
                'fetched_at' => optional($rate?->fetched_at)->toIso8601String(),
            ],
        ]);
    }

    /** Crea la compra con lo que quedó en la revisión. */
    public function confirm(Request $request, ExchangeRateService $rates)
    {
        $draft = $request->session()->get(self::DRAFT);

        if (! $draft) {
            return redirect()->route('market.index')->with('error', 'El borrador de la factura ya no está disponible.');
        }

        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'store' => 'nullable|string|max:120',
            'date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:120',
            'items.*.brand' => 'nullable|string|max:120',
            'items.*.size' => 'nullable|string|max:60',
            'items.*.quantity' => 'required|numeric|min:0.01',
            // Ya vienen convertidos a dólares con la tasa que se eligió arriba.
            'items.*.unit_price_usd' => 'required|numeric|min:0',
        ]);

        $rate = $rates->latest();
        $date = ! empty($data['date']) ? Carbon::parse($data['date']) : now();

        $trip = ShoppingTrip::create([
            'name' => ($data['name'] ?? null) ?: 'Mercado '.$date->format('d/m'),
            'store' => $data['store'] ?? null,
            'status' => 'active',
            'rate_bcv_usd' => $rate?->bcv_usd,
            'rate_parallel_usd' => $rate?->parallel_usd,
            'rate_bcv_eur' => $rate?->bcv_eur,
            'receipt_path' => $draft['receipt_path'],
            'created_by' => $request->user()->id,
            'created_at' => $date,
        ]);

        foreach ($data['items'] as $item) {
            $trip->items()->create([
                'name' => trim($item['name']),
                'brand' => $this->blankToNull($item['brand'] ?? null),
                'size' => $this->blankToNull($item['size'] ?? null),
                'quantity' => $item['quantity'],
                'unit_price_usd' => $item['unit_price_usd'],
            ]);
        }

        // El borrador ya es una compra: se suelta la sesión sin borrar la foto,
        // que a partir de ahora pertenece a la compra.
        $request->session()->forget(self::DRAFT);

        return redirect()->route('market.show', $trip)
            ->with('success', count($data['items']).' productos cargados desde la factura 🧾');
    }

    /** Tira el borrador y su foto. */
    public function discard(Request $request, ImageService $images)
    {
        $this->discardDraft($request, $images);

        return redirect()->route('market.index');
    }

    private function discardDraft(Request $request, ImageService $images): void
    {
        $draft = $request->session()->pull(self::DRAFT);

        if ($draft) {
            $images->delete($draft['receipt_path'] ?? null);
        }
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
