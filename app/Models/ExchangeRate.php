<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'bcv_usd' => 'decimal:4',
        'parallel_usd' => 'decimal:4',
        'bcv_eur' => 'decimal:4',
        'parallel_eur' => 'decimal:4',
        'rate_date' => 'date',
        'fetched_at' => 'datetime',
    ];

    /**
     * Convierte un monto en USD a las distintas referencias.
     * Permite pasar tasas explícitas (p. ej. el snapshot de un mercado);
     * si no, usa las de este registro.
     */
    public static function convert(
        float $usd,
        ?float $bcvUsd,
        ?float $parallelUsd,
        ?float $bcvEur
    ): array {
        $eur = ($bcvUsd && $bcvEur && $bcvEur > 0) ? $usd * ($bcvUsd / $bcvEur) : null;

        // USDT, como el euro, parte de los bolívares: cuántos USDT hacen falta
        // para pagar esos bolívares a la tasa paralela.
        $usdt = ($bcvUsd && $parallelUsd && $parallelUsd > 0) ? $usd * ($bcvUsd / $parallelUsd) : null;

        return [
            'usd' => round($usd, 2),
            'bcv' => $bcvUsd ? round($usd * $bcvUsd, 2) : null,
            'usdt' => $usdt !== null ? round($usdt, 2) : null,
            'eur' => $eur !== null ? round($eur, 2) : null,
        ];
    }

    public function convertUsd(float $usd): array
    {
        return self::convert(
            $usd,
            $this->bcv_usd ? (float) $this->bcv_usd : null,
            $this->parallel_usd ? (float) $this->parallel_usd : null,
            $this->bcv_eur ? (float) $this->bcv_eur : null,
        );
    }

    /** Monedas en las que se puede anotar un pago. USD es el dólar BCV, la base. */
    public const CURRENCIES = ['USD', 'VES', 'USDT', 'EUR'];

    /**
     * Pasa a dólares BCV un monto pagado en otra moneda: el camino inverso de
     * convert().
     *
     * Todo pasa por los bolívares: USDT a la tasa paralela y euros a la tasa
     * BCV del euro. Así convert() devuelve exactamente lo que se pagó.
     *
     * @param  array{bcv_usd: ?float, parallel_usd: ?float, bcv_eur: ?float}  $rates
     * @return float|null null si falta la tasa que hace falta
     */
    public static function toUsd(float $amount, string $currency, array $rates): ?float
    {
        if ($currency === 'USD') {
            return $amount;
        }

        $bcvUsd = $rates['bcv_usd'] ?? null;
        $parallelUsd = $rates['parallel_usd'] ?? null;
        $bcvEur = $rates['bcv_eur'] ?? null;

        $bolivares = match ($currency) {
            'VES' => $amount,
            'USDT' => $parallelUsd ? $amount * $parallelUsd : null,
            'EUR' => $bcvEur ? $amount * $bcvEur : null,
            default => null,
        };

        return ($bolivares !== null && $bcvUsd) ? $bolivares / $bcvUsd : null;
    }

    /**
     * Las tres tasas con la forma que se congela en mercados y gastos.
     *
     * @return array{bcv_usd: ?float, parallel_usd: ?float, bcv_eur: ?float}
     */
    public function snapshot(): array
    {
        return [
            'bcv_usd' => $this->bcv_usd !== null ? (float) $this->bcv_usd : null,
            'parallel_usd' => $this->parallel_usd !== null ? (float) $this->parallel_usd : null,
            'bcv_eur' => $this->bcv_eur !== null ? (float) $this->bcv_eur : null,
        ];
    }

    /** El día en que rige: el que marca el BCV o, si no lo trae, el de la descarga. */
    public function effectiveDate(): ?string
    {
        return $this->rate_date?->toDateString() ?? $this->fetched_at?->toDateString();
    }
}
