<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class FinanceController extends Controller
{
    /** Columnas de quien registró el gasto (incluye la foto de perfil). */
    private const MEMBER_COLUMNS = 'id,name,avatar_emoji,avatar_path,color';

    public function index(Request $request)
    {
        $categories = ExpenseCategory::orderBy('name')->get();

        // La portada es un resumen: 10 movimientos y el resto en el historial.
        $expenses = Expense::with(['category', 'creator:'.self::MEMBER_COLUMNS])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(10)
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
            'expenseCount' => Expense::count(),
            'stats' => [
                'weekTotal' => $weekTotal,
                'monthTotal' => $monthTotal,
            ],
            'byCategory' => $byCategory,
            'weeklyTrend' => $weeks,
        ]);
    }

    /**
     * Historial completo, con filtros y paginación.
     *
     * La portada solo enseña lo último; aquí se puede buscar un gasto viejo
     * por texto, fecha, categoría o quién lo registró, y ver su comprobante.
     */
    public function history(Request $request)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:120',
            'category' => 'nullable|string',
            'member' => 'nullable|string',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'receipts' => 'nullable|string',
        ]);

        $page = Expense::with(['category', 'creator:'.self::MEMBER_COLUMNS])
            ->tap(fn (Builder $q) => $this->applyFilters($q, $filters))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        // Totales del resultado filtrado, no del mes: si buscas "gasolina" de
        // julio, lo que quieres saber es cuánto fue eso.
        $matching = Expense::query()->tap(fn (Builder $q) => $this->applyFilters($q, $filters));

        return Inertia::render('Finance/History', [
            'filters' => [
                'q' => $filters['q'] ?? '',
                'category' => $filters['category'] ?? '',
                'member' => $filters['member'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'receipts' => $filters['receipts'] ?? '',
            ],
            'categories' => ExpenseCategory::orderBy('name')->get(),
            'members' => User::select('id', 'name', 'avatar_emoji', 'avatar_path', 'color')->orderBy('name')->get(),
            'expenses' => [
                'data' => collect($page->items())->values(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'totals' => [
                'count' => (clone $matching)->count(),
                'sum' => (float) (clone $matching)->sum('amount'),
            ],
        ]);
    }

    /** @param  array<string, mixed>  $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['q'] ?? null, fn (Builder $q, $text) => $q->where('description', 'like', '%'.$text.'%'))
            ->when($filters['category'] ?? null, fn (Builder $q, $id) => $q->where('expense_category_id', $id))
            ->when($filters['member'] ?? null, fn (Builder $q, $id) => $q->where('created_by', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '<=', $date))
            ->when(($filters['receipts'] ?? null) === '1', fn (Builder $q) => $q->whereNotNull('receipt_path'));
    }

    public function storeExpense(Request $request, ImageService $images)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'expense_category_id' => 'nullable|exists:expense_categories,id',
            'date' => 'required|date',
            'receipt' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192'],
        ]);

        unset($data['receipt']);

        $data['created_by'] = $request->user()->id;
        $data['receipt_path'] = $request->hasFile('receipt')
            ? $images->receipt($request->file('receipt'))
            : null;

        Expense::create($data);

        return back()->with('success', 'Gasto registrado');
    }

    public function destroyExpense(Expense $expense, ImageService $images)
    {
        $images->delete($expense->receipt_path);
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
