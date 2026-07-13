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

        $routines = Routine::with(['logs.completer:id,name,avatar_emoji,color'])
            ->orderBy('title')
            ->get()
            ->map(function ($routine) use ($today) {
                $lastLog = $routine->logs->first();
                $routine->done_today = $routine->logs
                    ->where('completed_at', '>=', $today)
                    ->isNotEmpty();
                $routine->last_completed = $lastLog?->completed_at;
                $routine->last_by = $lastLog?->completer;
                return $routine;
            });

        return Inertia::render('Household/Index', [
            'routines' => $routines,
            'stats' => [
                'doneToday' => $routines->where('done_today', true)->count(),
                'total' => $routines->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'frequency' => 'required|string|in:daily,weekly,monthly',
        ]);

        Routine::create($data);

        return back()->with('success', 'Rutina creada');
    }

    public function complete(Request $request, Routine $routine)
    {
        $routine->logs()->create([
            'completed_by' => $request->user()->id,
            'completed_at' => now(),
        ]);

        return back()->with('success', '¡Hecho! ✨');
    }

    public function destroy(Routine $routine)
    {
        $routine->delete();
        return back()->with('success', 'Rutina eliminada');
    }
}
