<?php

namespace App\Http\Controllers;

use App\Models\SavingsGoal;
use Illuminate\Http\Request;

class SavingsController extends Controller
{
    public function storeGoal(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'target_amount' => 'required|numeric|min:0.01',
            'target_date' => 'nullable|date',
            'emoji' => 'nullable|string|max:16',
            'color' => 'nullable|string|max:20',
        ]);

        $data['created_by'] = $request->user()->id;
        SavingsGoal::create($data);

        return back()->with('success', 'Meta de ahorro creada');
    }

    public function storeContribution(Request $request, SavingsGoal $goal)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'note' => 'nullable|string|max:255',
        ]);

        $goal->contributions()->create([
            ...$data,
            'contributed_by' => $request->user()->id,
        ]);

        $goal->refresh();
        $reached = $goal->current_amount >= (float) $goal->target_amount;

        return back()->with($reached ? 'celebrate' : 'success', $reached
            ? "¡Meta '{$goal->name}' alcanzada! 🎉"
            : 'Aporte registrado 💪');
    }

    public function destroyGoal(SavingsGoal $goal)
    {
        $goal->delete();
        return back()->with('success', 'Meta eliminada');
    }
}
