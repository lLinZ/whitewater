<?php

namespace App\Console\Commands;

use App\Console\SchedulerStatus;
use App\Models\Routine;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RemindRoutines extends Command
{
    protected $signature = 'routines:remind';

    protected $description = 'Envía un push con las rutinas del hogar pendientes de hoy';

    public function handle(PushService $push): int
    {
        $today = Carbon::today();

        // Deja constancia aunque hoy no haya nada que notificar: lo que
        // interesa saber después es si el comando llegó a ejecutarse.
        SchedulerStatus::reminderRan();

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
