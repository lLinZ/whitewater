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

    /**
     * Lee la foto y deja el borrador listo para revisar.
     *
     * Con `trip`, la factura se suma a un mercado que ya está en curso: una
     * misma salida de compras puede pasar por varios supermercados.
     */
    public function scan(Request $request, ImageService $images, InvoiceScanner $scanner)
    {
        abort_unless($scanner->isConfigured(), 404);

        $request->validate([
            'invoice' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192'],
            'trip' => ['nullable', 'integer'],
        ]);

        $trip = null;

        if ($request->filled('trip')) {
            $trip = ShoppingTrip::find($request->integer('trip'));

            // Se comprueba antes de leer: la lectura gasta cuota.
            if (! $trip || $trip->status !== 'active') {
                return back()->with('error', 'Ese mercado ya está terminado. Escanea la factura como un mercado nuevo.');
            }
        }

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
            'trip_id' => $trip?->id,
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

        $trip = $this->draftTrip($draft);

        // Sumándose a un mercado se convierte con la tasa de ese mercado: el
        // mercado pasa sus dólares a bolívares con su propia tasa, y con otra
        // los bolívares de esta factura dejarían de cuadrar con la foto.
        $tripRates = $trip?->rate_bcv_usd !== null ? $trip->rates : null;

        return Inertia::render('Market/Invoice', [
            'invoice' => $draft['data'],
            'receiptUrl' => Storage::disk('public')->url($draft['receipt_path']),
            // El mercado al que se suma, o null si la factura crea uno nuevo.
            'trip' => $trip ? [
                'id' => $trip->id,
                'name' => $trip->name,
                'item_count' => $trip->item_count,
                'total_usd' => $trip->total_usd,
            ] : null,
            'rates' => $tripRates ?? $rates->latest()?->snapshot(),
        ]);
    }

    /** Crea la compra (o completa la que estaba en curso) con lo revisado. */
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

        $date = ! empty($data['date']) ? Carbon::parse($data['date']) : now();
        $store = $this->blankToNull($data['store'] ?? null);
        $trip = $this->draftTrip($draft);
        $adding = $trip !== null;

        if (! $trip) {
            $trip = new ShoppingTrip([
                'name' => ($data['name'] ?? null) ?: 'Mercado '.$date->format('d/m'),
                'store' => $store,
                'status' => 'active',
                'created_by' => $request->user()->id,
                'created_at' => $date,
            ]);
            // Las mismas tasas con las que la revisión convirtió los precios.
            $trip->snapshotRates($rates->latest()?->snapshot())->save();
        }

        $receipt = $trip->receipts()->create([
            'receipt_path' => $draft['receipt_path'],
            'store' => $store,
            'date' => $date->toDateString(),
        ]);

        foreach ($data['items'] as $item) {
            $trip->items()->create([
                'shopping_receipt_id' => $receipt->id,
                'name' => trim($item['name']),
                'brand' => $this->blankToNull($item['brand'] ?? null),
                'size' => $this->blankToNull($item['size'] ?? null),
                'quantity' => $item['quantity'],
                'unit_price_usd' => $item['unit_price_usd'],
            ]);
        }

        // El borrador ya es una factura de la compra: se suelta la sesión sin
        // borrar la foto, que a partir de ahora pertenece a la compra.
        $request->session()->forget(self::DRAFT);

        $count = count($data['items']);

        return redirect()->route('market.show', $trip)->with('success', $adding
            ? "{$count} productos".($store ? " de {$store}" : '').' añadidos al mercado 🧾'
            : "{$count} productos cargados desde la factura 🧾");
    }

    /** Tira el borrador y su foto, y vuelve de donde se escaneó. */
    public function discard(Request $request, ImageService $images)
    {
        $trip = $this->draftTrip($request->session()->get(self::DRAFT));

        $this->discardDraft($request, $images);

        return $trip
            ? redirect()->route('market.show', $trip)
            : redirect()->route('market.index');
    }

    /**
     * El mercado al que se suma el borrador, si sigue en curso.
     *
     * Si entretanto se terminó o se borró, la factura no se pierde: se
     * revisa y se guarda como un mercado nuevo.
     */
    private function draftTrip(?array $draft): ?ShoppingTrip
    {
        $id = $draft['trip_id'] ?? null;

        return $id ? ShoppingTrip::where('status', 'active')->find($id) : null;
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
