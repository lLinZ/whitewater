<?php

namespace App\Http\Controllers;

use App\Services\ExchangeRateService;
use Illuminate\Http\RedirectResponse;

class RatesController extends Controller
{
    public function refresh(ExchangeRateService $service): RedirectResponse
    {
        $rate = $service->fetch();

        if (! $rate) {
            return back()->with('error', 'No se pudieron actualizar las tasas. Revisa tu conexión.');
        }

        return back()->with('success', 'Tasas actualizadas ✅');
    }
}
