<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\InventoryLog;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    public function index()
    {
        $ingredients = Ingredient::orderBy('name')->get();
        // Sugerencia de lista de compras para el jueves de mercado (predicción manual por ahora)
        $projections = $this->inventoryService->getShoppingListProjections(now());

        return Inertia::render('Kitchen/Inventory', [
            'ingredients' => $ingredients,
            'projections' => $projections
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string',
            'stock' => 'required|numeric|min:0',
            'unit' => 'required|string',
            'min_stock' => 'nullable|numeric|min:0',
        ]);

        Ingredient::create($validated);

        return redirect()->back();
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string',
            'stock' => 'required|numeric|min:0',
            'unit' => 'required|string',
            'min_stock' => 'nullable|numeric|min:0',
        ]);

        $oldStock = $ingredient->stock;
        $ingredient->update($validated);

        if ($oldStock != $validated['stock']) {
            InventoryLog::create([
                'ingredient_id' => $ingredient->id,
                'quantity_changed' => $validated['stock'] - $oldStock,
                'type' => 'adjustment',
                'note' => 'Ajuste manual de inventario'
            ]);
        }

        return redirect()->back();
    }

    public function destroy(Ingredient $ingredient)
    {
        $ingredient->delete();
        return redirect()->back();
    }
}
