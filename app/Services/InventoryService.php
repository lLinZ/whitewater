<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\Ingredient;
use App\Models\WeeklyPlan;
use App\Models\InventoryLog;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Check if a recipe has enough stock to be prepared.
     */
    public function hasEnoughStock(Recipe $recipe): bool
    {
        foreach ($recipe->ingredients as $ingredient) {
            $required = $ingredient->pivot->quantity;
            if ($ingredient->stock < $required) {
                return false;
            }
        }
        return true;
    }

    /**
     * Deduct ingredients for a specific weekly plan entry.
     */
    public function deductForPlan(WeeklyPlan $plan)
    {
        if ($plan->is_deducted) {
            return;
        }

        DB::transaction(function () use ($plan) {
            $recipe = $plan->recipe;
            foreach ($recipe->ingredients as $ingredient) {
                $required = $ingredient->pivot->quantity;

                // Update stock
                $ingredient->decrement('stock', $required);

                // Log the deduction
                InventoryLog::create([
                    'ingredient_id' => $ingredient->id,
                    'weekly_plan_id' => $plan->id,
                    'quantity_changed' => -$required,
                    'type' => 'deduction',
                    'note' => "Deducción automática por receta: {$recipe->title}"
                ]);
            }

            $plan->update(['is_deducted' => true]);
        });
    }

    /**
     * Get ingredients projected for the next bi-weekly shopping trip.
     * (Analyzing current stock vs next 14 days of planning).
     */
    public function getShoppingListProjections($startDate)
    {
        $endDate = $startDate->copy()->addDays(14);
        
        $plans = WeeklyPlan::with('recipe.ingredients')
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get();

        $requirements = [];

        foreach ($plans as $plan) {
            foreach ($plan->recipe->ingredients as $ingredient) {
                if (!isset($requirements[$ingredient->id])) {
                    $requirements[$ingredient->id] = [
                        'name' => $ingredient->name,
                        'unit' => $ingredient->unit,
                        'needed' => 0,
                        'current_stock' => $ingredient->stock
                    ];
                }
                $requirements[$ingredient->id]['needed'] += $ingredient->pivot->quantity;
            }
        }

        $shoppingList = [];
        foreach ($requirements as $id => $data) {
            $toBuy = $data['needed'] - $data['current_stock'];
            if ($toBuy > 0) {
                $shoppingList[] = [
                    'ingredient_id' => $id,
                    'name' => $data['name'],
                    'quantity' => $toBuy,
                    'unit' => $data['unit']
                ];
            }
        }

        return $shoppingList;
    }
}
