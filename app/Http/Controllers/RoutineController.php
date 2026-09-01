<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class RoutineController extends Controller
{
    public function index(Request $request)
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        $routines = Routine::with(['logs.completer:id,name,avatar_emoji,avatar_path,color'])
            ->orderBy('title')
            ->get()
            ->map(fn ($routine) => $this->shape($routine, $today, $monthStart));

        // Solo cuentan las que tocan hoy: una rutina de miércoles y viernes
        // no debe ensuciar el progreso de un lunes.
        $dueToday = $routines->where('due_today', true);

        return Inertia::render('Household/Index', [
            'routines' => $routines->values(),
            'stats' => [
                'doneToday' => $dueToday->where('done_today', true)->count(),
                'total' => $dueToday->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Routine::create($this->payload($request));

        return back()->with('success', 'Rutina creada');
    }

    public function update(Request $request, Routine $routine)
    {
        $routine->update($this->payload($request));

        return back()->with('success', 'Rutina actualizada');
    }

    private function payload(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'frequency' => 'required|string|in:daily,weekly,monthly',
            'days' => 'nullable|array',
            'days.*' => 'integer|min:1|max:7', // ISO: 1 = lunes … 7 = domingo
        ]);

        $days = array_values(array_unique(array_map('intval', $data['days'] ?? [])));
        sort($days);

        return [
            'title' => trim($data['title']),
            'frequency' => $data['frequency'],
            // Los días solo tienen sentido en las semanales.
            'days' => ($data['frequency'] === 'weekly' && $days) ? $days : null,
        ];
    }

    public function complete(Request $request, Routine $routine)
    {
        $routine->logs()->create([
            'completed_by' => $request->user()->id,
            'completed_at' => now(),
        ]);

        return back()->with('success', '¡Hecho! ✨');
    }

    /** Deshace la última vez que se marcó como hecha (dentro de su ventana). */
    public function uncomplete(Routine $routine)
    {
        $routine->load('logs');

        $last = $routine->logs
            ->where('completed_at', '>=', $routine->windowStart(Carbon::today()))
            ->first();

        $last?->delete();

        return back()->with('success', 'Marcada como pendiente');
    }

    public function destroy(Routine $routine)
    {
        $routine->delete();

        return back()->with('success', 'Rutina eliminada');
    }

    private function shape(Routine $routine, Carbon $today, Carbon $monthStart): array
    {
        $lastLog = $routine->logs->first();

        return [
            'id' => $routine->id,
            'title' => $routine->title,
            'frequency' => $routine->frequency,
            'days' => $routine->selectedDays(),
            'schedule_label' => $routine->scheduleLabel(),
            'due_today' => $routine->isDueOn($today),
            'done_today' => $routine->isDoneOn($today),
            // Cuántas veces se hizo este mes: sin esto una rutina es una
            // casilla que se marca y se olvida, no algo con historia.
            'done_this_month' => $routine->logs
                ->where('completed_at', '>=', $monthStart)
                ->count(),
            'last_completed' => optional($lastLog?->completed_at)->toIso8601String(),
            'last_by' => $lastLog?->completer,
        ];
    }
}
