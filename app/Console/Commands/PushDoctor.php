<?php

namespace App\Console\Commands;

use App\Console\SchedulerStatus;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Console\Command;

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
        $this->line('== Tareas programadas ==');
        $reminderTime = config('services.webpush.reminder_time', '20:00');
        $this->line('Hora configurada: '.$reminderTime);

        // El latido es lo que zanja un "no me llegan las notificaciones": se
        // escribe cada minuto, así que dice si algo llama a `schedule:run` sin
        // esperar a la hora del recordatorio.
        $beat = SchedulerStatus::lastBeat();

        if (SchedulerStatus::isAlive()) {
            $this->info('✓ El scheduler está corriendo (último latido '.$beat->diffForHumans().').');
        } elseif ($beat) {
            $ok = false;
            $this->error('✗ El scheduler se paró: último latido '.$beat->diffForHumans().'.');
            $this->line('  Revisa que el cron siga activo: systemctl status cron');
        } else {
            $ok = false;
            $this->error('✗ Nada está llamando a `schedule:run`: falta el cron del scheduler.');
            $this->line('  Instálalo y añádelo al crontab de www-data:');
            $this->line('  sudo apt install -y cron && sudo systemctl enable --now cron');
            $this->line("  ( crontab -u www-data -l 2>/dev/null; echo '* * * * * cd ".base_path()." && ".PHP_BINARY." artisan schedule:run >> /dev/null 2>&1' ) | crontab -u www-data -");
            $this->line('  Espera un minuto y vuelve a correr este comando.');
        }

        // Que el recordatorio no se haya enviado todavía NO es un fallo: solo
        // corre una vez al día. Marcarlo en rojo mandaría a buscar un problema
        // que no existe.
        $reminder = SchedulerStatus::lastReminder();

        if ($reminder) {
            $this->line('Último recordatorio enviado: '.$reminder->diffForHumans()." ({$reminder->toDateTimeString()})");
        } else {
            $this->line("Todavía no se ha enviado ningún recordatorio: sale a las {$reminderTime}.");
            $this->line('  Para forzarlo ahora: php artisan routines:remind');
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
