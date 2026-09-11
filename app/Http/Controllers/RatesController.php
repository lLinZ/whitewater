<?php

namespace App\Http\Controllers;

use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

    /**
     * Las tasas vigentes un día, para mostrar la conversión mientras se
     * anota un gasto. Son las mismas que usará el servidor al guardarlo.
     *
     * `date` es el día del que son: si no hay tasa guardada del día pedido,
     * difiere, y la pantalla lo avisa.
     */
    public function day(Request $request, ExchangeRateService $service): JsonResponse
    {
        $day = $request->validate(['fecha' => 'required|date'])['fecha'];
        $rate = $service->forDate($day);

        return response()->json([
            'rates' => $rate ? [...$rate->snapshot(), 'date' => $rate->effectiveDate()] : null,
        ]);
    }
}
