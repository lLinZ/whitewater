<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PushDoctor extends Command
{
    protected $signature = 'push:doctor {--send : Envía una notificación de prueba a todos los suscritos}';

    protected $description = 'Revisa por qué no llegan las notificaciones push';

    public function handle(PushService $push): int
    {
        $ok = true;

        $this->line('');
        $this->line('== Claves VAPID ==');
        $problem = $push->configurationError();
        if ($problem === null) {
            $this->info('✓ Las claves VAPID están configuradas y son válidas.');
        } else {
            $ok = false;
            $this->error('✗ '.$problem);
            $this->line('  Genera un par nuevo con: php artisan webpush:vapid');
            $this->line('  Pégalas en .env y corre: php artisan config:cache');
        }

        if (! config('services.webpush.subject')) {
            $this->warn('! VAPID_SUBJECT vacío. Pon "mailto:tu-correo@ejemplo.com" en .env.');
        }

        $this->line('');
        $this->line('== HTTPS ==');
        $url = (string) config('app.url');
        if (str_starts_with($url, 'https://')) {
            $this->info("✓ APP_URL usa HTTPS ({$url}).");
        } else {
            $ok = false;
            $this->error("✗ APP_URL no es HTTPS ({$url}).");
            $this->line('  Sin HTTPS el iPhone no registra el service worker ni acepta push.');
        }

        $this->line('');
        $this->line('== Dispositivos suscritos ==');
        $subs = PushSubscription::with('user:id,name')->get();
        if ($subs->isEmpty()) {
            $ok = false;
            $this->error('✗ Nadie tiene notificaciones activadas.');
            $this->line('  En el iPhone: Safari → Compartir → Añadir a inicio, abre desde el ícono,');
            $this->line('  entra a Hogar y activa "Recordatorios de tareas".');
        } else {
            foreach ($subs->groupBy('user_id') as $rows) {
                $name = $rows->first()->user?->name ?? 'desconocido';
                $encodings = $rows->pluck('content_encoding')->unique()->implode(', ');
                $this->info("✓ {$name}: {$rows->count()} dispositivo(s) [{$encodings}]");
            }

            $legacy = $subs->where('content_encoding', 'aesgcm')->count();
            if ($legacy > 0) {
                $this->warn("! {$legacy} suscripción(es) usan el cifrado viejo 'aesgcm'.");
                $this->line('  Safari/iOS las descarta. Se corrigen solas al abrir la app, o con:');
                $this->line('  php artisan migrate');
            }
        }

        $this->line('');
        $this->line('== Recordatorio programado ==');
        $this->line('Hora configurada: '.config('services.webpush.reminder_time', '20:00'));

        // El dato que zanja de verdad un "no me llegan las notificaciones":
        // si el recordatorio no se ha ejecutado nunca, lo que falta es el cron,
        // no las claves ni las suscripciones.
        $lastRun = Cache::get(RemindRoutines::LAST_RUN_KEY);

        if ($lastRun) {
            $this->info('✓ Última ejecución: '.Carbon::parse($lastRun)->diffForHumans()." ({$lastRun})");
        } else {
            $ok = false;
            $this->error('✗ El recordatorio no se ha ejecutado nunca: falta el cron del scheduler.');
            $this->line('  Añádelo con `crontab -e`:');
            $this->line('  * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');
            $this->line('  Compruébalo con: php artisan schedule:list');
        }

        if ($this->option('send')) {
            $this->line('');
            $this->line('== Envío de prueba ==');
            $users = User::has('pushSubscriptions')->get();

            foreach ($users as $user) {
                $r = $push->deliver($user, '🌊 Whitewater', 'Prueba desde push:doctor', ['url' => '/hogar']);
                $this->line("{$user->name}: {$r['sent']} enviada(s), {$r['failed']} fallida(s)");
                foreach ($r['errors'] as $error) {
                    $ok = false;
                    $this->error('  → '.$error);
                }
            }
        } else {
            $this->line('');
            $this->line('Para probar un envío real: php artisan push:doctor --send');
        }

        $this->line('');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
