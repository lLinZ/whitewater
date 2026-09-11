<?php

namespace App\Http\Controllers;

use App\Models\ShoppingReceipt;
use App\Models\ShoppingTrip;
use App\Models\ShoppingItem;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\ExchangeRateService;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Throwable;

class MarketController extends Controller
{
    public function index()
    {
        // No forzamos red aquí: mientras compras (posible mala señal) la página
        // debe abrir al instante con la última tasa guardada. El refresco
        // automático ocurre en el Inicio y con el botón "Actualizar".
        $trips = ShoppingTrip::with(['items', 'receipts', 'creator:id,name,avatar_emoji,avatar_path,color'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => $this->shapeTrip($t));

        return Inertia::render('Market/Index', [
            'trips' => $trips,
        ]);
    }

    public function store(Request $request, ExchangeRateService $rates)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'store' => 'nullable|string|max:120',
        ]);

        // Snapshot de la última tasa guardada (sin bloquear con una llamada de red).
        $rate = $rates->latest();

        $trip = ShoppingTrip::create([
            'name' => ($data['name'] ?? null) ?: 'Mercado '.now()->format('d/m'),
            'store' => $data['store'] ?? null,
            'status' => 'active',
            'rate_bcv_usd' => $rate?->bcv_usd,
            'rate_parallel_usd' => $rate?->parallel_usd,
            'rate_bcv_eur' => $rate?->bcv_eur,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('market.show', $trip);
    }

    public function show(ShoppingTrip $trip)
    {
        $trip->load(['items', 'receipts', 'creator:id,name,avatar_emoji,avatar_path,color', 'expense']);

        // Mercado anterior (para comparar), excluyendo el actual.
        $previous = ShoppingTrip::where('id', '!=', $trip->id)
            ->where('created_at', '<=', $trip->created_at)
            ->orderByDesc('created_at')
            ->with('items')
            ->first();

        return Inertia::render('Market/Show', [
            'trip' => $this->shapeTrip($trip),
            'previous' => $previous ? [
                'id' => $previous->id,
                'name' => $previous->name,
                'date' => optional($previous->created_at)->toDateString(),
                'total_usd' => $previous->total_usd,
            ] : null,
            'catalog' => $this->productCatalog(),
        ]);
    }

    /**
     * Catálogo de productos ya comprados alguna vez, distinguidos por
     * producto + marca + presentación (Harina · Harina PAN · 1 kg), con las
     * veces comprado y el último precio conocido. Sirve para autocompletar.
     */
    private function productCatalog(): array
    {
        $grouped = ShoppingItem::query()
            // El último precio conocido ignora los productos anotados sin
            // precio: si no, un pendiente reciente borraría la sugerencia.
            ->selectRaw('name, brand, size, COUNT(*) as cnt, MAX(CASE WHEN unit_price_usd IS NOT NULL THEN id END) as last_priced_id')
            ->groupBy('name', 'brand', 'size')
            ->orderByRaw('MAX(id) DESC') // los comprados más recientemente primero
            ->limit(400)
            ->get();

        $lastItems = ShoppingItem::whereIn('id', $grouped->pluck('last_priced_id')->filter())
            ->get(['id', 'unit_price_usd', 'created_at'])
            ->keyBy('id');

        return $grouped->map(function ($row) use ($lastItems) {
            $last = $row->last_priced_id ? $lastItems->get($row->last_priced_id) : null;
            return [
                'name' => $row->name,
                'brand' => $row->brand,
                'size' => $row->size,
                'label' => ShoppingItem::buildLabel($row->name, $row->brand, $row->size),
                'count' => (int) $row->cnt,
                'last_price' => $last ? (float) $last->unit_price_usd : null,
                'last_date' => optional($last?->created_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    public function addItem(Request $request, ShoppingTrip $trip)
    {
        $trip->items()->create($this->itemPayload($request));

        return back(303);
    }

    public function updateItem(Request $request, ShoppingTrip $trip, ShoppingItem $item)
    {
        abort_unless($item->shopping_trip_id === $trip->id, 404);
        $item->update($this->itemPayload($request));

        return back(303);
    }

    /**
     * Campos de un producto. El precio es opcional: se puede anotar el
     * producto en el súper y completarlo al llegar a casa.
     */
    private function itemPayload(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'brand' => 'nullable|string|max:120',
            'size' => 'nullable|string|max:60',
            'unit_price_usd' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|numeric|min:0.01',
        ]);

        return [
            'name' => trim($data['name']),
            'brand' => $this->nullIfBlank($data['brand'] ?? null),
            'size' => $this->nullIfBlank($data['size'] ?? null),
            'unit_price_usd' => $data['unit_price_usd'] ?? null,
            'quantity' => $data['quantity'] ?? 1,
        ];
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function deleteItem(ShoppingTrip $trip, ShoppingItem $item)
    {
        abort_unless($item->shopping_trip_id === $trip->id, 404);
        $item->delete();

        return back(303);
    }

    public function finish(Request $request, ShoppingTrip $trip, ImageService $images)
    {
        $data = $request->validate([
            'as_expense' => 'nullable|boolean',
        ]);

        // Un mercado ya terminado vuelve aquí para registrar el gasto que no
        // se registró al cerrarlo.
        $wasDone = $trip->status === 'done';
        $copies = [];

        try {
            // Todo o nada: si el gasto falla, el mercado sigue abierto y se
            // puede volver a terminar. Cerrarlo primero lo dejaba "Terminado"
            // sin gasto y sin botón para reintentar.
            DB::transaction(function () use ($data, $trip, $request, $images, &$copies) {
                $trip->update(['status' => 'done']);

                if (! empty($data['as_expense']) && $trip->total_usd > 0 && ! $trip->expense_id) {
                    $category = ExpenseCategory::firstOrCreate(
                        ['name' => 'Mercado'],
                        ['color' => '#7c3aed']
                    );

                    $expenses = $this->createExpenses($trip, $category, $request->user()->id, $images, $copies);

                    // Marca de "ya se registró": con varias facturas hay varios
                    // gastos, y basta con apuntar al primero para no duplicarlos.
                    $trip->update(['expense_id' => $expenses[0]->id]);
                }
            });
        } catch (Throwable $e) {
            // Las copias de las facturas no viven en la base de datos: si el
            // gasto no llegó a guardarse, sobran.
            collect($copies)->each(fn ($path) => $images->delete($path));

            throw $e;
        }

        return $wasDone
            ? redirect()->route('market.show', $trip)->with('success', 'Registrado en Finanzas')
            : redirect()->route('market.index')->with('celebrate', 'Mercado terminado 🛒');
    }

    /**
     * Un gasto por factura, más uno por lo anotado a mano.
     *
     * Comprar en dos supermercados son dos pagos, cada uno con su factura: en
     * Finanzas deben verse separados y con su foto. Con una sola factura (o
     * ninguna) sale un único gasto, igual que siempre.
     *
     * @param  list<string>  $copies  recibe las rutas de las fotos copiadas
     * @return list<Expense>
     */
    private function createExpenses(ShoppingTrip $trip, ExpenseCategory $category, int $userId, ImageService $images, array &$copies): array
    {
        $trip->load(['items', 'receipts']);

        $receiptIds = $trip->receipts->pluck('id')->all();
        $groups = $trip->receipts->map(fn ($receipt) => [
            'receipt' => $receipt,
            'amount' => $this->sumItems($trip->items->where('shopping_receipt_id', $receipt->id)),
        ]);
        $groups->push([
            'receipt' => null,
            'amount' => $this->sumItems($trip->items->reject(
                fn ($item) => in_array($item->shopping_receipt_id, $receiptIds)
            )),
        ]);

        $groups = $groups->filter(fn ($group) => $group['amount'] > 0)->values();
        $split = $groups->count() > 1;

        return $groups->map(function ($group) use ($trip, $category, $userId, $images, $split, &$copies) {
            $receipt = $group['receipt'];

            // El gasto se queda con su propia copia de la factura: cada
            // registro es dueño de su archivo y borrar uno no debe dejar al
            // otro sin comprobante.
            $copy = $images->copy($receipt?->receipt_path);

            if ($copy) {
                $copies[] = $copy;
            }

            return Expense::create([
                'amount' => $group['amount'],
                'expense_category_id' => $category->id,
                'description' => $split
                    ? $trip->name.' · '.($receipt ? ($receipt->store ?: 'Factura') : 'Anotado a mano')
                    : $trip->name,
                // La fecha de la factura es cuándo salió el dinero.
                'date' => $receipt?->date?->toDateString() ?? now()->toDateString(),
                'created_by' => $userId,
                'receipt_path' => $copy,
            ]);
        })->all();
    }

    private function sumItems($items): float
    {
        return round($items->sum(fn ($item) => (float) $item->unit_price_usd * (float) $item->quantity), 2);
    }

    /** Quita una factura escaneada por error, con sus productos. */
    public function destroyReceipt(ShoppingTrip $trip, ShoppingReceipt $receipt, ImageService $images)
    {
        abort_unless($receipt->shopping_trip_id === $trip->id, 404);

        $count = $receipt->items()->count();
        $receipt->items()->delete();
        $images->delete($receipt->receipt_path);
        $receipt->delete();

        return back(303)->with('success', "Factura quitada, con sus {$count} productos");
    }

    public function destroy(ShoppingTrip $trip, ImageService $images)
    {
        $trip->receipts->each(fn ($receipt) => $images->delete($receipt->receipt_path));
        $trip->delete();

        return redirect()->route('market.index')->with('success', 'Mercado eliminado');
    }

    private function shapeTrip(ShoppingTrip $trip): array
    {
        return [
            'id' => $trip->id,
            'name' => $trip->name,
            'store' => $trip->store,
            'status' => $trip->status,
            'created_at' => optional($trip->created_at)->toDateString(),
            'total_usd' => $trip->total_usd,
            'item_count' => $trip->item_count,
            'pending_price_count' => $trip->pending_price_count,
            'rates' => [
                'bcv_usd' => $trip->rate_bcv_usd !== null ? (float) $trip->rate_bcv_usd : null,
                'parallel_usd' => $trip->rate_parallel_usd !== null ? (float) $trip->rate_parallel_usd : null,
                'bcv_eur' => $trip->rate_bcv_eur !== null ? (float) $trip->rate_bcv_eur : null,
            ],
            'has_expense' => (bool) $trip->expense_id,
            'receipts' => $trip->receipts->map(fn ($r) => [
                'id' => $r->id,
                'url' => $r->receipt_url,
                'store' => $r->store,
                'date' => $r->date?->toDateString(),
                'item_count' => $trip->relationLoaded('items')
                    ? $trip->items->where('shopping_receipt_id', $r->id)->count()
                    : 0,
                'total_usd' => $trip->relationLoaded('items')
                    ? $this->sumItems($trip->items->where('shopping_receipt_id', $r->id))
                    : 0,
            ])->values(),
            'items' => $trip->relationLoaded('items') ? $trip->items->map(fn ($i) => [
                'id' => $i->id,
                'receipt_id' => $i->shopping_receipt_id,
                'name' => $i->name,
                'brand' => $i->brand,
                'size' => $i->size,
                'label' => $i->label,
                'unit_price_usd' => $i->unit_price_usd !== null ? (float) $i->unit_price_usd : null,
                'quantity' => (float) $i->quantity,
                'subtotal_usd' => $i->subtotal_usd,
            ])->values() : [],
        ];
    }
}
