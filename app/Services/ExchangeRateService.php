<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExchangeRateService
{
    private const BASE = 'https://ve.dolarapi.com/v1';

    /** Horas tras las cuales una tasa se considera "vieja". */
    private const STALE_HOURS = 8;

    /**
     * Última tasa almacenada (o null si no hay ninguna).
     */
    public function latest(): ?ExchangeRate
    {
        return ExchangeRate::orderByDesc('fetched_at')->orderByDesc('id')->first();
    }

    /**
     * Devuelve la tasa vigente; si falta o está vieja, intenta refrescar
     * en segundo plano sin romper nunca la petición.
     */
    public function current(): ?ExchangeRate
    {
        $latest = $this->latest();

        $stale = ! $latest
            || ! $latest->fetched_at
            || $latest->fetched_at->lt(now()->subHours(self::STALE_HOURS));

        if ($stale) {
            // Refresco best-effort con timeout corto: nunca debe demorar la carga.
            $fresh = $this->fetch(quick: true);
            if ($fresh) {
                return $fresh;
            }
        }

        return $latest;
    }

    /**
     * Obtiene tasas de DolarAPI y las persiste. Devuelve el registro o null.
     * En modo $quick usa timeouts cortos y sin reintentos (para no bloquear
     * una carga de página); el modo normal reintenta (comando / botón manual).
     */
    public function fetch(bool $quick = false): ?ExchangeRate
    {
        try {
            $usd = $this->get(self::BASE.'/dolares', $quick);
            $eur = $this->get(self::BASE.'/euros', $quick);

            if (! $usd) {
                return null;
            }

            $bcvUsd = $this->pick($usd, 'oficial');
            $parallelUsd = $this->pick($usd, 'paralelo');
            $bcvEur = $eur ? $this->pick($eur, 'oficial') : null;
            $parallelEur = $eur ? $this->pick($eur, 'paralelo') : null;

            if (! $bcvUsd && ! $parallelUsd) {
                return null;
            }

            $rateDate = null;
            foreach ($usd as $row) {
                if (($row['fuente'] ?? null) === 'oficial' && ! empty($row['fechaActualizacion'])) {
                    $rateDate = Carbon::parse($row['fechaActualizacion'])->toDateString();
                    break;
                }
            }

            return ExchangeRate::create([
                'bcv_usd' => $bcvUsd,
                'parallel_usd' => $parallelUsd,
                'bcv_eur' => $bcvEur,
                'parallel_eur' => $parallelEur,
                'source' => 'dolarapi',
                'rate_date' => $rateDate,
                'fetched_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('ExchangeRate fetch falló: '.$e->getMessage());
            return null;
        }
    }

    /**
     * GET con reintento y fallback de verificación SSL (Windows/XAMPP suelen
     * carecer de bundle CA, lo que rompería la verificación por defecto).
     */
    private function get(string $url, bool $quick = false): ?array
    {
        $timeout = $quick ? 4 : 12;
        $tries = $quick ? 1 : 3;

        try {
            $res = Http::timeout($timeout)->retry($tries, 300)->acceptJson()->get($url);
            if ($res->successful()) {
                return $res->json();
            }
        } catch (Throwable $e) {
            // Reintento sin verificar el certificado (API pública de solo lectura).
            try {
                $res = Http::withoutVerifying()->timeout($timeout)->acceptJson()->get($url);
                if ($res->successful()) {
                    return $res->json();
                }
            } catch (Throwable $e2) {
                Log::warning("ExchangeRate GET {$url} falló: ".$e2->getMessage());
            }
        }

        return null;
    }

    /**
     * Extrae el "promedio" de una fuente concreta del payload de DolarAPI.
     */
    private function pick(array $rows, string $fuente): ?float
    {
        foreach ($rows as $row) {
            if (($row['fuente'] ?? null) === $fuente) {
                $val = $row['promedio'] ?? $row['venta'] ?? $row['compra'] ?? null;
                return $val !== null ? (float) $val : null;
            }
        }

        return null;
    }
}
