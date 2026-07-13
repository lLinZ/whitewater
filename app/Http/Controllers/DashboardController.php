<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Debt;
use App\Models\SavingsGoal;
use App\Models\WeeklyPlan;
use App\Models\Routine;
use App\Models\RoutineLog;
use App\Models\User;
use App\Services\ExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request, ExchangeRateService $rates)
    {
        // Sincroniza las tasas del día al abrir el Inicio (best-effort, timeout
        // corto; si no hay internet, se conserva la última guardada).
        $rates->current();

        $today = Carbon::today();

        // Menú de hoy
        $todayMenu = WeeklyPlan::with('recipe')
            ->whereDate('date', $today)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'meal_type' => $p->meal_type,
                'recipe' => $p->recipe?->title,
                'is_deducted' => (bool) $p->is_deducted,
            ]);

        // Finanzas de la semana
        $weekTotal = (float) Expense::whereBetween('date', [
            Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek(),
        ])->sum('amount');

        // Ahorros y deudas
        $goals = SavingsGoal::all();
        $debts = Debt::all();

        // Rutinas pendientes hoy
        $routines = Routine::with('logs')->get();
        $pendingRoutines = $routines->filter(function ($r) use ($today) {
            return $r->logs->where('completed_at', '>=', $today)->isEmpty();
        })->count();

        return Inertia::render('Dashboard', [
            'greetingName' => $request->user()->name,
            'members' => User::select('id', 'name', 'avatar_emoji', 'color')->get(),
            'todayMenu' => $todayMenu,
            'weekTotal' => $weekTotal,
            'savings' => [
                'total' => (float) $goals->sum(fn ($g) => $g->current_amount),
                'target' => (float) $goals->sum('target_amount'),
                'goals' => $goals->map(fn ($g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'emoji' => $g->emoji,
                    'color' => $g->color,
                    'progress' => $g->progress,
                    'current' => $g->current_amount,
                    'target' => (float) $g->target_amount,
                ])->values(),
            ],
            'debt' => [
                'remaining' => (float) $debts->sum(fn ($d) => $d->remaining_amount),
                'count' => $debts->count(),
            ],
            'routines' => [
                'pending' => $pendingRoutines,
                'total' => $routines->count(),
            ],
            'streak' => $this->activityStreak(),
            'achievements' => $this->achievements(),
        ]);
    }

    /**
     * Días consecutivos (terminando hoy o ayer) con actividad registrada
     * en el hogar: gastos, comidas cocinadas o rutinas completadas.
     */
    private function activityStreak(): int
    {
        $dates = collect()
            ->merge(Expense::pluck('date'))
            ->merge(RoutineLog::pluck('completed_at'))
            ->merge(WeeklyPlan::where('is_deducted', true)->pluck('updated_at'))
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->flip();

        $streak = 0;
        $cursor = Carbon::today();

        // Permite que la racha "siga viva" si hubo actividad ayer aunque hoy aún no.
        if (! $dates->has($cursor->toDateString()) && $dates->has($cursor->copy()->subDay()->toDateString())) {
            $cursor->subDay();
        }

        while ($dates->has($cursor->toDateString())) {
            $streak++;
            $cursor->subDay();
        }

        return $streak;
    }

    private function achievements(): array
    {
        $hasContribution = SavingsGoal::has('contributions')->exists();
        $hasCooked = WeeklyPlan::where('is_deducted', true)->exists();
        $hasPayment = Debt::has('payments')->exists();
        $streak = $this->activityStreak();

        return [
            ['key' => 'first_save', 'label' => 'Primer ahorro', 'emoji' => '🌱', 'unlocked' => $hasContribution],
            ['key' => 'cook', 'label' => 'Manos a la masa', 'emoji' => '👩‍🍳', 'unlocked' => $hasCooked],
            ['key' => 'payer', 'label' => 'Menos deuda', 'emoji' => '💸', 'unlocked' => $hasPayment],
            ['key' => 'streak7', 'label' => 'Racha de 7', 'emoji' => '🔥', 'unlocked' => $streak >= 7],
        ];
    }
}
