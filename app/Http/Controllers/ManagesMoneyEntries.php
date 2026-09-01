<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesReceipts;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Lo que comparten un abono a una deuda y un aporte a una meta: mismo
 * formulario, mismo comprobante y misma forma al llegar al front, para que
 * una sola pantalla de detalle sirva para los dos.
 */
trait ManagesMoneyEntries
{
    use HandlesReceipts;

    /** Campos del formulario de monto (abono, aporte). */
    protected function validateEntry(Request $request): array
    {
        return $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'note' => 'nullable|string|max:255',
        ]);
    }

    /**
     * Página de movimientos con la misma forma para deudas y metas.
     *
     * @param  string  $member  relación con quien lo registró ('payer', 'contributor')
     */
    protected function entryPage(LengthAwarePaginator $page, string $member): array
    {
        return [
            'data' => collect($page->items())->map(fn ($entry) => [
                'id' => $entry->id,
                'amount' => (float) $entry->amount,
                'date' => $entry->date->toDateString(),
                'note' => $entry->note,
                'receipt_url' => $entry->receipt_url,
                'member' => $entry->{$member},
            ])->values(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ];
    }

    /** Resumen del historial completo: cuántos, cuánto y desde cuándo. */
    protected function entryTotals(Relation $entries): array
    {
        // El builder de la relación ya trae el filtro por deuda/meta; se clona
        // por agregado para que un where no contamine al siguiente.
        $base = $entries->getQuery();

        $count = (clone $base)->count();
        $sum = (float) (clone $base)->sum('amount');
        $first = (clone $base)->min('date');

        return [
            'count' => $count,
            'sum' => $sum,
            'average' => $count > 0 ? round($sum / $count, 2) : 0.0,
            'this_month' => (float) (clone $base)
                ->where('date', '>=', Carbon::now()->startOfMonth()->toDateString())
                ->sum('amount'),
            'first_date' => $first ? Carbon::parse($first)->toDateString() : null,
        ];
    }
}
