<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\SavingsGoal;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DebtController extends Controller
{
    use ManagesMoneyEntries;

    public function index(Request $request)
    {
        // La portada muestra los últimos movimientos de cada tarjeta; el
        // historial completo vive en la vista de detalle.
        $debts = Debt::with(['payments' => fn ($q) => $q->with('payer:id,name,avatar_emoji,avatar_path,color')->limit(3)])
            ->withCount('payments')
            ->orderBy('created_at')
            ->get();

        $goals = SavingsGoal::with(['contributions' => fn ($q) => $q->with('contributor:id,name,avatar_emoji,avatar_path,color')->limit(3)])
            ->withCount('contributions')
            ->orderBy('created_at')
            ->get();

        return Inertia::render('Money/Index', [
            'debts' => $debts,
            'goals' => $goals,
            'summary' => [
                'totalDebt' => (float) $debts->sum('remaining_amount'),
                'totalPaid' => (float) $debts->sum('paid_amount'),
                'totalSaved' => (float) $goals->sum('current_amount'),
                'savingsTarget' => (float) $goals->sum('target_amount'),
            ],
        ]);
    }

    /** Ficha de la deuda con TODO su historial de abonos. */
    public function show(Request $request, Debt $debt)
    {
        $payments = $debt->payments()
            ->with('payer:id,name,avatar_emoji,avatar_path,color')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Money/Show', [
            'account' => [
                'kind' => 'debt',
                'id' => $debt->id,
                'name' => $debt->name,
                'emoji' => $debt->emoji,
                'color' => $debt->color,
                'target' => (float) $debt->total_amount,
                'moved' => $debt->paid_amount,
                'remaining' => $debt->remaining_amount,
                'progress' => $debt->progress,
                'lender' => $debt->lender,
                'monthly_payment' => $debt->monthly_payment !== null ? (float) $debt->monthly_payment : null,
                'due_day' => $debt->due_day,
                'target_date' => null,
            ],
            'entries' => $this->entryPage($payments, 'payer'),
            'totals' => $this->entryTotals($debt->payments()),
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

    public function updateDebt(Request $request, Debt $debt)
    {
        $debt->update($request->validate([
            'name' => 'required|string|max:120',
            'lender' => 'nullable|string|max:120',
            'total_amount' => 'required|numeric|min:0.01',
            'monthly_payment' => 'nullable|numeric|min:0',
            'due_day' => 'nullable|integer|min:1|max:31',
            'emoji' => 'nullable|string|max:16',
            'color' => 'nullable|string|max:20',
        ]));

        return back()->with('success', 'Deuda actualizada');
    }

    public function storePayment(Request $request, Debt $debt, ImageService $images)
    {
        $data = $this->validateEntry($request);

        $debt->payments()->create([
            ...$data,
            'paid_by' => $request->user()->id,
            'receipt_path' => $this->storeReceipt($request, $images),
        ]);

        $debt->refresh();
        $paidOff = $debt->remaining_amount <= 0;

        return back()->with($paidOff ? 'celebrate' : 'success', $paidOff
            ? "¡Terminaste de pagar {$debt->name}! 🎉"
            : 'Abono registrado');
    }

    /**
     * Edita un abono ya registrado.
     *
     * Sirve sobre todo para adjuntarle el comprobante a un pago viejo: el
     * recibo aparece muchas veces después de haber anotado el abono.
     */
    public function updatePayment(Request $request, Debt $debt, DebtPayment $payment, ImageService $images)
    {
        abort_unless($payment->debt_id === $debt->id, 404);

        $payment->update($this->validateEntry($request));
        $this->syncReceipt($request, $images, $payment);

        return back()->with('success', 'Abono actualizado');
    }

    public function destroyPayment(Debt $debt, DebtPayment $payment, ImageService $images)
    {
        abort_unless($payment->debt_id === $debt->id, 404);

        $images->delete($payment->receipt_path);
        $payment->delete();

        return back()->with('success', 'Abono eliminado');
    }

    public function destroyDebt(Debt $debt, ImageService $images)
    {
        // La FK borra los abonos en cascada, pero no sus fotos: hay que
        // limpiarlas a mano o el disco se llena de recibos huérfanos.
        $debt->payments()->pluck('receipt_path')->each(fn ($path) => $images->delete($path));

        $debt->delete();

        return back()->with('success', 'Deuda eliminada');
    }
}
