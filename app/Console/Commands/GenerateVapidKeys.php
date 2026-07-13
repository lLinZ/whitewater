<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Genera un par de claves VAPID para Web Push (cópialas al .env)';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $this->error('No se pudieron generar las claves: '.$e->getMessage());
            $this->line('En Windows/XAMPP puede requerir OPENSSL_CONF apuntando a openssl.cnf.');
            return self::FAILURE;
        }

        $this->info('Añade esto a tu .env:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();

        return self::SUCCESS;
    }
}
