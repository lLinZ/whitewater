<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\SavingsGoal;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DebtController extends Controller
{
    public function index(Request $request)
    {
        $debts = Debt::with(['payments.payer:id,name,avatar_emoji,color', 'creator:id,name'])
            ->orderBy('created_at')
            ->get();

        $goals = SavingsGoal::with(['contributions.contributor:id,name,avatar_emoji,color', 'creator:id,name'])
            ->orderBy('created_at')
            ->get();

        return Inertia::render('Money/Index', [
            'debts' => $debts,
            'goals' => $goals,
            'summary' => [
                'totalDebt' => (float) $debts->sum('remaining_amount'),
                'totalSaved' => (float) $goals->sum('current_amount'),
                'savingsTarget' => (float) $goals->sum('target_amount'),
            ],
        ]);
    }

    public function storeDebt(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'lender' => 'nullable|string|max:120',
            'total_amount' => 'required|numeric|min:0.01',
            'monthly_payment' => 'nullable|numeric|min:0',
            'due_day' => 'nullable|integer|min:1|max:31',
            'emoji' => 'nullable|string|max:16',
            'color' => 'nullable|string|max:20',
        ]);

        $data['created_by'] = $request->user()->id;
        Debt::create($data);

        return back()->with('success', 'Deuda registrada');
    }

    public function storePayment(Request $request, Debt $debt)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'note' => 'nullable|string|max:255',
        ]);

        $debt->payments()->create([
            ...$data,
            'paid_by' => $request->user()->id,
        ]);

        $debt->refresh();
        $paidOff = $debt->remaining_amount <= 0;

        return back()->with($paidOff ? 'celebrate' : 'success', $paidOff
            ? "¡Terminaste de pagar {$debt->name}! 🎉"
            : 'Abono registrado');
    }

    public function destroyDebt(Debt $debt)
    {
        $debt->delete();
        return back()->with('success', 'Deuda eliminada');
    }
}
