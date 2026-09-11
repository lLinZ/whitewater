<?php

namespace App\Models\Concerns;

/**
 * Tasas congeladas en el registro: las del día en que ocurrió.
 *
 * Un mercado o un gasto de hace un mes debe seguir valiendo los mismos
 * bolívares, USDT y euros aunque hoy la tasa sea otra. Por eso no se busca la
 * tasa al mostrarlo: se guarda al registrarlo, en rate_bcv_usd,
 * rate_parallel_usd y rate_bcv_eur.
 *
 * `rates` las devuelve con la misma forma que ExchangeRate::snapshot(), que es
 * la que entienden convert(), toUsd() y el convertUsd() del front.
 */
trait HasRateSnapshot
{
    public function initializeHasRateSnapshot(): void
    {
        $this->mergeCasts([
            'rate_bcv_usd' => 'decimal:4',
            'rate_parallel_usd' => 'decimal:4',
            'rate_bcv_eur' => 'decimal:4',
        ]);
    }

    /** @param  array{bcv_usd: ?float, parallel_usd: ?float, bcv_eur: ?float}|null  $rates */
    public function snapshotRates(?array $rates): static
    {
        $this->rate_bcv_usd = $rates['bcv_usd'] ?? null;
        $this->rate_parallel_usd = $rates['parallel_usd'] ?? null;
        $this->rate_bcv_eur = $rates['bcv_eur'] ?? null;

        return $this;
    }

    /** @return array{bcv_usd: ?float, parallel_usd: ?float, bcv_eur: ?float} */
    public function getRatesAttribute(): array
    {
        return [
            'bcv_usd' => $this->rate_bcv_usd !== null ? (float) $this->rate_bcv_usd : null,
            'parallel_usd' => $this->rate_parallel_usd !== null ? (float) $this->rate_parallel_usd : null,
            'bcv_eur' => $this->rate_bcv_eur !== null ? (float) $this->rate_bcv_eur : null,
        ];
    }
}
