<?php

namespace App\Http\Controllers;

use App\Models\SavingsContribution;
use App\Models\SavingsGoal;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SavingsController extends Controller
{
    use ManagesMoneyEntries;

    /** Ficha de la meta con TODO su historial de aportes. */
    public function show(Request $request, SavingsGoal $goal)
    {
        $contributions = $goal->contributions()
            ->with('contributor:id,name,avatar_emoji,avatar_path,color')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Money/Show', [
            'account' => [
                'kind' => 'goal',
                'id' => $goal->id,
                'name' => $goal->name,
                'emoji' => $goal->emoji,
                'color' => $goal->color,
                'target' => (float) $goal->target_amount,
                'moved' => $goal->current_amount,
                'remaining' => $goal->remaining_amount,
                'progress' => $goal->progress,
                'lender' => null,
                'monthly_payment' => null,
                'due_day' => null,
                'target_date' => optional($goal->target_date)->toDateString(),
            ],
            'entries' => $this->entryPage($contributions, 'contributor'),
            'totals' => $this->entryTotals($goal->contributions()),
        ]);
    }

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

    public function updateGoal(Request $request, SavingsGoal $goal)
    {
        $goal->update($request->validate([
            'name' => 'required|string|max:120',
            'target_amount' => 'required|numeric|min:0.01',
            'target_date' => 'nullable|date',
            'emoji' => 'nullable|string|max:16',
            'color' => 'nullable|string|max:20',
        ]));

        return back()->with('success', 'Meta actualizada');
    }

    public function storeContribution(Request $request, SavingsGoal $goal, ImageService $images)
    {
        $data = $this->validateEntry($request);

        $goal->contributions()->create([
            ...$data,
            'contributed_by' => $request->user()->id,
            'receipt_path' => $this->storeReceipt($request, $images),
        ]);

        $goal->refresh();
        $reached = $goal->current_amount >= (float) $goal->target_amount;

        return back()->with($reached ? 'celebrate' : 'success', $reached
            ? "¡Meta '{$goal->name}' alcanzada! 🎉"
            : 'Aporte registrado 💪');
    }

    public function destroyContribution(SavingsGoal $goal, SavingsContribution $contribution, ImageService $images)
    {
        abort_unless($contribution->savings_goal_id === $goal->id, 404);

        $images->delete($contribution->receipt_path);
        $contribution->delete();

        return back()->with('success', 'Aporte eliminado');
    }

    public function destroyGoal(SavingsGoal $goal, ImageService $images)
    {
        // Igual que con las deudas: la cascada borra las filas, las fotos no.
        $goal->contributions()->pluck('receipt_path')->each(fn ($path) => $images->delete($path));

        $goal->delete();

        return back()->with('success', 'Meta eliminada');
    }
}
