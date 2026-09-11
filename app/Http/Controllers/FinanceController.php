<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesReceipts;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\ImageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FinanceController extends Controller
{
    use HandlesReceipts;

    /** Columnas de quien registró el gasto (incluye la foto de perfil). */
    private const MEMBER_COLUMNS = 'id,name,avatar_emoji,avatar_path,color';

    public function index(Request $request)
    {
        // withCount: en el gestor de categorías hay que poder ver a cuántos
        // gastos afecta borrar una antes de hacerlo.
        $categories = ExpenseCategory::withCount('expenses')->orderBy('name')->get();

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

    public function storeExpense(Request $request, ImageService $images, ExchangeRateService $rates)
    {
        $data = $this->validateExpense($request);

        $expense = new Expense([
            ...Arr::only($data, ['description', 'expense_category_id', 'date']),
            'created_by' => $request->user()->id,
        ]);
        $this->applyAmount($expense, $data, $rates);

        // La foto se guarda después de convertir: si falta la tasa, la
        // validación corta antes y no queda un archivo huérfano.
        $expense->receipt_path = $this->storeReceipt($request, $images);
        $expense->save();

        return back()->with('success', 'Gasto registrado');
    }

    /**
     * Edita un gasto ya registrado.
     *
     * El caso que más se usa es adjuntarle el comprobante a un gasto viejo:
     * la factura casi nunca está a mano en el momento de anotarlo.
     */
    public function updateExpense(Request $request, Expense $expense, ImageService $images, ExchangeRateService $rates)
    {
        $data = $this->validateExpense($request);
        $dateChanged = $expense->date?->toDateString() !== Carbon::parse($data['date'])->toDateString();

        $expense->fill(Arr::only($data, ['description', 'expense_category_id', 'date']));
        $this->applyAmount($expense, $data, $rates, refreshRates: $dateChanged);
        $expense->save();

        $this->syncReceipt($request, $images, $expense);

        return back()->with('success', 'Gasto actualizado');
    }

    /**
     * Pasa lo pagado a dólares BCV con la tasa del día del gasto, no la del
     * día en que se anota.
     *
     * Al editar, la tasa solo se vuelve a buscar si cambió la fecha: corregir
     * la descripción de un gasto en bolívares no debe moverle el monto porque
     * entretanto llegó otra tasa de ese mismo día.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyAmount(Expense $expense, array $data, ExchangeRateService $rates, bool $refreshRates = true): void
    {
        if ($refreshRates || $expense->rate_bcv_usd === null) {
            $expense->snapshotRates($rates->forDate($data['date'])?->snapshot());
        }

        if (! $expense->setPaid((float) $data['amount'], $data['currency'] ?? 'USD')) {
            throw ValidationException::withMessages([
                'amount' => 'No hay tasa guardada para convertir esa moneda. Anótalo en dólares o actualiza las tasas desde el Inicio.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validateExpense(Request $request): array
    {
        return $request->validate([
            // En la moneda en que se pagó; sin moneda, dólares.
            'amount' => 'required|numeric|min:0.01',
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
            'description' => 'required|string|max:255',
            'expense_category_id' => 'nullable|exists:expense_categories,id',
            'date' => 'required|date',
        ]);
    }

    public function destroyExpense(Expense $expense, ImageService $images)
    {
        $images->delete($expense->receipt_path);
        $expense->delete();

        return back()->with('success', 'Gasto eliminado');
    }

    public function storeCategory(Request $request)
    {
        ExpenseCategory::create($this->validateCategory($request));

        return back()->with('success', 'Categoría creada');
    }

    public function updateCategory(Request $request, ExpenseCategory $category)
    {
        $category->update($this->validateCategory($request));

        return back()->with('success', 'Categoría actualizada');
    }

    public function destroyCategory(ExpenseCategory $category)
    {
        // La clave foránea deja los gastos en "Sin categoría" en vez de
        // borrarlos: nadie espera perder el historial por reorganizar etiquetas.
        $affected = $category->expenses()->count();

        $category->delete();

        return back()->with('success', $affected > 0
            ? "Categoría eliminada. {$affected} gasto(s) quedaron sin categoría."
            : 'Categoría eliminada');
    }

    /** @return array<string, mixed> */
    private function validateCategory(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:60',
            'color' => 'nullable|string|max:20',
        ]);
    }
}
