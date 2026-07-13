<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use Illuminate\Console\Command;

class SyncExchangeRates extends Command
{
    protected $signature = 'rates:sync';

    protected $description = 'Obtiene las tasas del día (BCV, paralelo/USDT, EUR) desde DolarAPI';

    public function handle(ExchangeRateService $service): int
    {
        $rate = $service->fetch();

        if (! $rate) {
            $this->error('No se pudieron obtener las tasas.');
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Tasas actualizadas — BCV: %s | USDT: %s | EUR(BCV): %s',
            $rate->bcv_usd, $rate->parallel_usd, $rate->bcv_eur
        ));

        return self::SUCCESS;
    }
}
