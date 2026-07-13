<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function index(Request $request)
    {
        $categories = ExpenseCategory::orderBy('name')->get();

        $expenses = Expense::with(['category', 'creator:id,name,avatar_emoji,color'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        $startWeek = Carbon::now()->startOfWeek();
        $startMonth = Carbon::now()->startOfMonth();

        $weekTotal = (float) Expense::whereBetween('date', [$startWeek, Carbon::now()->endOfWeek()])->sum('amount');
        $monthTotal = (float) Expense::whereBetween('date', [$startMonth, Carbon::now()->endOfMonth()])->sum('amount');

        // Desglose por categoría (mes actual) para donut
        $byCategory = Expense::with('category')
            ->whereBetween('date', [$startMonth, Carbon::now()->endOfMonth()])
            ->get()
            ->groupBy(fn ($e) => $e->category->name ?? 'Sin categoría')
            ->map(fn ($group) => (float) $group->sum('amount'))
            ->map(fn ($total, $name) => ['name' => $name, 'total' => $total])
            ->values();

        // Últimas 6 semanas para barras
        $weeks = collect(range(5, 0))->map(function ($i) {
            $start = Carbon::now()->startOfWeek()->subWeeks($i);
            $end = (clone $start)->endOfWeek();
            return [
                'label' => $start->format('d/m'),
                'total' => (float) Expense::whereBetween('date', [$start, $end])->sum('amount'),
            ];
        });

        return Inertia::render('Finance/Index', [
            'categories' => $categories,
            'expenses' => $expenses,
            'stats' => [
                'weekTotal' => $weekTotal,
                'monthTotal' => $monthTotal,
            ],
            'byCategory' => $byCategory,
            'weeklyTrend' => $weeks,
        ]);
    }

    public function storeExpense(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'expense_category_id' => 'nullable|exists:expense_categories,id',
            'date' => 'required|date',
        ]);

        $data['created_by'] = $request->user()->id;
        Expense::create($data);

        return back()->with('success', 'Gasto registrado');
    }

    public function destroyExpense(Expense $expense)
    {
        $expense->delete();
        return back()->with('success', 'Gasto eliminado');
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'color' => 'nullable|string|max:20',
        ]);

        ExpenseCategory::create($data);

        return back()->with('success', 'Categoría creada');
    }
}
