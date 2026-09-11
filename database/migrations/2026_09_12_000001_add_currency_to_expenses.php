<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un gasto recuerda en qué moneda se pagó y con qué tasas se convirtió.
 *
 * `amount` sigue siendo dólares BCV, la base de totales y gráficos. Lo nuevo:
 * lo que se pagó tal cual (`original_amount` en `currency`) y las tasas del
 * día del gasto, congeladas como en los mercados, para que el equivalente en
 * bolívares, USDT y euros no cambie cuando cambie la tasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('currency', 4)->default('USD')->after('amount');
            $table->decimal('original_amount', 14, 2)->default(0)->after('currency');
            $table->decimal('rate_bcv_usd', 14, 4)->nullable();
            $table->decimal('rate_parallel_usd', 14, 4)->nullable();
            $table->decimal('rate_bcv_eur', 14, 4)->nullable();
        });

        // Todo lo anotado hasta hoy se anotó en dólares.
        DB::table('expenses')->update(['original_amount' => DB::raw('amount')]);

        // Y se le ponen las tasas de su día, con la misma regla que
        // ExchangeRateService::forDate(). Va escrita aquí y no llamando al
        // servicio: una migración debe dar lo mismo aunque el servicio cambie.
        $effective = 'DATE(COALESCE(rate_date, fetched_at))';

        DB::table('expenses')->select('date')->distinct()->pluck('date')->each(function ($date) use ($effective) {
            $day = substr((string) $date, 0, 10);

            $rate = DB::table('exchange_rates')
                ->whereRaw("{$effective} <= ?", [$day])
                ->orderByRaw("{$effective} DESC")->orderByDesc('fetched_at')->orderByDesc('id')
                ->first()
                ?? DB::table('exchange_rates')
                    ->orderByRaw("{$effective} ASC")->orderBy('fetched_at')->orderBy('id')
                    ->first();

            if ($rate) {
                DB::table('expenses')->whereDate('date', $day)->update([
                    'rate_bcv_usd' => $rate->bcv_usd,
                    'rate_parallel_usd' => $rate->parallel_usd,
                    'rate_bcv_eur' => $rate->bcv_eur,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['currency', 'original_amount', 'rate_bcv_usd', 'rate_parallel_usd', 'rate_bcv_eur']);
        });
    }
};
