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

        return [
            'usd' => round($usd, 2),
            'bcv' => $bcvUsd ? round($usd * $bcvUsd, 2) : null,
            'usdt' => $parallelUsd ? round($usd * $parallelUsd, 2) : null,
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
}
