<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\Ingredient;
use App\Models\WeeklyPlan;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class KitchenController extends Controller
{
    public function recipes()
    {
        $recipes = Recipe::with('ingredients')->orderBy('title')->get();
        $ingredients = Ingredient::orderBy('name')->get();

        return Inertia::render('Kitchen/Recipes', [
            'recipes' => $recipes,
            'ingredients' => $ingredients,
        ]);
    }

    public function planner(Request $request)
    {
        $recipes = Recipe::with('ingredients')->orderBy('title')->get();

        $start = Carbon::now()->startOfWeek();
        $end = Carbon::now()->endOfWeek();

        $plans = WeeklyPlan::with('recipe')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $days = [];
        $dayNames = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        for ($i = 0; $i < 7; $i++) {
            $current = $start->copy()->addDays($i);
            $days[] = [
                'date' => $current->toDateString(),
                'label' => $dayNames[$i],
                'short' => mb_substr($dayNames[$i], 0, 3),
                'isToday' => $current->isToday(),
            ];
        }

        // Disponibilidad de stock por receta
        $recipes->each(function ($recipe) {
            $available = true;
            foreach ($recipe->ingredients as $ing) {
                if ((float) $ing->stock < (float) $ing->pivot->quantity) {
                    $available = false;
                    break;
                }
            }
            $recipe->is_available = $available;
        });

        return Inertia::render('Kitchen/Planner', [
            'recipes' => $recipes,
            'plans' => $plans,
            'serverDays' => $days,
        ]);
    }

    public function assignPlan(Request $request)
    {
        $validated = $request->validate([
            'recipe_id' => 'required|exists:recipes,id',
            'date' => 'required|date',
            'meal_type' => 'required|string|in:breakfast,lunch,dinner',
        ]);

        WeeklyPlan::create([
            'date' => $validated['date'],
            'meal_type' => $validated['meal_type'],
            'recipe_id' => $validated['recipe_id'],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Añadido al menú');
    }

    public function cookPlan(WeeklyPlan $plan, InventoryService $inventory)
    {
        if ($plan->is_deducted) {
            return back()->with('error', 'Ya se descontó del inventario');
        }

        $inventory->deductForPlan($plan);

        return back()->with('celebrate', '¡Cocinado! Inventario actualizado 🍽️');
    }

    public function deletePlan(WeeklyPlan $plan)
    {
        $plan->delete();
        return back()->with('success', 'Eliminado del menú');
    }

    public function storeRecipe(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|array',
            'instructions' => 'nullable|string',
            'prep_time_minutes' => 'nullable|integer|min:0',
            'linked_ingredients' => 'nullable|array',
        ]);

        $recipe = Recipe::create($validated);
        $this->syncIngredients($recipe, $validated['linked_ingredients'] ?? []);

        return back()->with('success', 'Receta guardada');
    }

    public function updateRecipe(Request $request, Recipe $recipe)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|array',
            'instructions' => 'nullable|string',
            'prep_time_minutes' => 'nullable|integer|min:0',
            'linked_ingredients' => 'nullable|array',
        ]);

        $recipe->update($validated);
        $this->syncIngredients($recipe, $validated['linked_ingredients'] ?? []);

        return back()->with('success', 'Receta actualizada');
    }

    public function destroyRecipe(Recipe $recipe)
    {
        $recipe->delete();
        return back()->with('success', 'Receta eliminada');
    }

    private function syncIngredients(Recipe $recipe, array $items): void
    {
        $syncData = [];
        foreach ($items as $item) {
            if (empty($item['ingredient_id'])) {
                continue;
            }
            $syncData[$item['ingredient_id']] = [
                'quantity' => $item['quantity'] ?? 0,
                'unit' => $item['unit'] ?? null,
            ];
        }
        $recipe->ingredients()->sync($syncData);
    }
}
