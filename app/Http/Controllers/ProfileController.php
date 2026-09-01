<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\RoutineLog;
use App\Models\SavingsContribution;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'stats' => $this->stats($request->user()),
            'household' => User::query()
                ->whereKeyNot($request->user()->id)
                ->select('id', 'name', 'avatar_emoji', 'avatar_path', 'color')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('success', 'Perfil actualizado');
    }

    /**
     * Guarda solo la apariencia (color y modo claro/oscuro).
     *
     * Va aparte de update() porque se dispara con un toque en un color: pedir
     * nombre y correo válidos para eso convertiría un ajuste visual en un
     * formulario que puede fallar.
     */
    public function updateAppearance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'color' => ['sometimes', 'string', 'max:20'],
            'theme' => ['sometimes', 'string', 'in:light,dark,system'],
            'avatar_emoji' => ['sometimes', 'string', 'max:16'],
        ]);

        $request->user()->fill($data)->save();

        return back();
    }

    /** Sube (o reemplaza) la foto de perfil. */
    public function updateAvatar(Request $request, ImageService $images): RedirectResponse
    {
        $request->validate([
            // 8 MB: las fotos de iPhone pesan bastante y luego se reducen.
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192'],
        ]);

        $user = $request->user();
        $previous = $user->avatar_path;

        $user->forceFill(['avatar_path' => $images->avatar($request->file('avatar'))])->save();

        $images->delete($previous);

        return Redirect::route('profile.edit')->with('success', 'Foto actualizada 📸');
    }

    /** Vuelve al avatar de emoji. */
    public function destroyAvatar(Request $request, ImageService $images): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            $images->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return Redirect::route('profile.edit')->with('success', 'Foto eliminada');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request, ImageService $images): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        $images->delete($user->avatar_path);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Lo que ha aportado esta persona al hogar. Un perfil sin esto es un
     * formulario; con esto es la ficha del miembro.
     */
    private function stats(User $user): array
    {
        $monthStart = Carbon::now()->startOfMonth();

        return [
            'member_since' => optional($user->created_at)->toIso8601String(),
            'routines_done' => RoutineLog::where('completed_by', $user->id)->count(),
            'routines_month' => RoutineLog::where('completed_by', $user->id)
                ->where('completed_at', '>=', $monthStart)->count(),
            'expenses_logged' => Expense::where('created_by', $user->id)->count(),
            'expenses_month' => (float) Expense::where('created_by', $user->id)
                ->where('date', '>=', $monthStart)->sum('amount'),
            'contributed' => (float) SavingsContribution::where('contributed_by', $user->id)->sum('amount'),
            'debt_paid' => (float) Debt::query()
                ->join('debt_payments', 'debts.id', '=', 'debt_payments.debt_id')
                ->where('debt_payments.paid_by', $user->id)
                ->sum('debt_payments.amount'),
        ];
    }
}
