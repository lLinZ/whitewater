<?php

namespace App\Http\Controllers;

use App\Models\ShoppingTrip;
use App\Models\ShoppingItem;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\ExchangeRateService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MarketController extends Controller
{
    public function index()
    {
        // No forzamos red aquí: mientras compras (posible mala señal) la página
        // debe abrir al instante con la última tasa guardada. El refresco
        // automático ocurre en el Inicio y con el botón "Actualizar".
        $trips = ShoppingTrip::with(['items', 'creator:id,name,avatar_emoji,color'])
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
        $trip->load(['items', 'creator:id,name,avatar_emoji,color', 'expense']);

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
     * Catálogo de productos ya comprados alguna vez: nombre, veces comprado
     * y último precio conocido. Sirve para autocompletar en el próximo mercado.
     */
    private function productCatalog(): array
    {
        $grouped = ShoppingItem::query()
            ->selectRaw('name, COUNT(*) as cnt, MAX(id) as last_id')
            ->groupBy('name')
            ->orderByRaw('MAX(id) DESC') // los comprados más recientemente primero
            ->limit(300)
            ->get();

        $lastItems = ShoppingItem::whereIn('id', $grouped->pluck('last_id'))
            ->get(['id', 'unit_price_usd', 'created_at'])
            ->keyBy('id');

        return $grouped->map(function ($row) use ($lastItems) {
            $last = $lastItems->get($row->last_id);
            return [
                'name' => $row->name,
                'count' => (int) $row->cnt,
                'last_price' => (float) ($last->unit_price_usd ?? 0),
                'last_date' => optional($last?->created_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    public function addItem(Request $request, ShoppingTrip $trip)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'unit_price_usd' => 'required|numeric|min:0',
            'quantity' => 'nullable|numeric|min:0.01',
        ]);

        $trip->items()->create([
            'name' => $data['name'],
            'unit_price_usd' => $data['unit_price_usd'],
            'quantity' => $data['quantity'] ?? 1,
        ]);

        return back(303);
    }

    public function deleteItem(ShoppingTrip $trip, ShoppingItem $item)
    {
        abort_unless($item->shopping_trip_id === $trip->id, 404);
        $item->delete();

        return back(303);
    }

    public function finish(Request $request, ShoppingTrip $trip)
    {
        $data = $request->validate([
            'as_expense' => 'nullable|boolean',
        ]);

        $trip->update(['status' => 'done']);

        if (! empty($data['as_expense']) && $trip->total_usd > 0 && ! $trip->expense_id) {
            $category = ExpenseCategory::firstOrCreate(
                ['name' => 'Mercado'],
                ['color' => '#7c3aed']
            );

            $expense = Expense::create([
                'amount' => $trip->total_usd,
                'expense_category_id' => $category->id,
                'description' => $trip->name,
                'date' => now()->toDateString(),
                'created_by' => $request->user()->id,
            ]);

            $trip->update(['expense_id' => $expense->id]);
        }

        return redirect()->route('market.index')->with('celebrate', 'Mercado terminado 🛒');
    }

    public function destroy(ShoppingTrip $trip)
    {
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
            'rates' => [
                'bcv_usd' => $trip->rate_bcv_usd !== null ? (float) $trip->rate_bcv_usd : null,
                'parallel_usd' => $trip->rate_parallel_usd !== null ? (float) $trip->rate_parallel_usd : null,
                'bcv_eur' => $trip->rate_bcv_eur !== null ? (float) $trip->rate_bcv_eur : null,
            ],
            'has_expense' => (bool) $trip->expense_id,
            'items' => $trip->relationLoaded('items') ? $trip->items->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'unit_price_usd' => (float) $i->unit_price_usd,
                'quantity' => (float) $i->quantity,
                'subtotal_usd' => $i->subtotal_usd,
            ])->values() : [],
        ];
    }
}
