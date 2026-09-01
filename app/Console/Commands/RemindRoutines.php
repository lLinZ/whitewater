<?php

namespace App\Console\Commands;

use App\Models\Routine;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class RemindRoutines extends Command
{
    protected $signature = 'routines:remind';

    protected $description = 'Envía un push con las rutinas del hogar pendientes de hoy';

    /**
     * Rastro de la última ejecución.
     *
     * Sirve para responder a "las notificaciones no llegan": si esto está
     * vacío, el comando no se ha ejecutado nunca y el problema es el cron del
     * servidor, no las claves ni las suscripciones.
     */
    public const LAST_RUN_KEY = 'routines.remind.last_run';

    public function handle(PushService $push): int
    {
        $today = Carbon::today();

        Cache::forever(self::LAST_RUN_KEY, now()->toIso8601String());

        // Solo las que tocan hoy: una rutina de miércoles y viernes no debe
        // aparecer en el recordatorio de un lunes.
        $pending = Routine::with('logs')->get()->filter->isPendingOn($today);

        if ($pending->isEmpty()) {
            $this->info('No hay rutinas pendientes para hoy. Nada que notificar.');

            return self::SUCCESS;
        }

        $count = $pending->count();
        $names = $pending->take(3)->pluck('title')->implode(', ');
        $body = "Quedan {$count} ".($count === 1 ? 'tarea' : 'tareas').": {$names}".($count > 3 ? '…' : '');

        $users = User::has('pushSubscriptions')->get();

        if ($users->isEmpty()) {
            $this->warn('Nadie tiene notificaciones activadas. Actívalas en Hogar → Recordatorios.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($users as $user) {
            $total += $push->sendToUser($user, '🧹 Tareas del hogar', $body, ['url' => '/hogar', 'tag' => 'routines']);
        }

        $this->info("Recordatorio enviado a {$users->count()} miembro(s), {$total} dispositivo(s).");

        if ($total === 0) {
            $this->warn('Ningún envío salió. Revisa `php artisan push:doctor`.');
        }

        return self::SUCCESS;
    }
}
