<?php

namespace App\Console\Commands;

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

        $pending = Routine::with('logs')->get()->filter(
            fn ($r) => $r->logs->where('completed_at', '>=', $today)->isEmpty()
        );

        if ($pending->isEmpty()) {
            $this->info('No hay rutinas pendientes. Nada que notificar.');
            return self::SUCCESS;
        }

        $count = $pending->count();
        $names = $pending->take(3)->pluck('title')->implode(', ');
        $body = "Quedan {$count} ".($count === 1 ? 'tarea' : 'tareas').": {$names}".($count > 3 ? '…' : '');

        $users = User::has('pushSubscriptions')->get();
        $total = 0;
        foreach ($users as $user) {
            $total += $push->sendToUser($user, '🧹 Tareas del hogar', $body, ['url' => '/hogar']);
        }

        $this->info("Recordatorio enviado a {$users->count()} miembro(s), {$total} dispositivo(s).");
        return self::SUCCESS;
    }
}
