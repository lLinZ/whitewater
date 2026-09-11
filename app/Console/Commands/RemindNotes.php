<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PushService;
use Illuminate\Console\Command;

/**
 * Avisa de las notas que faltan por montar en Monday.
 *
 * Corre cada minuto y solo le escribe a quien eligió esta hora y tiene notas
 * pendientes: quien no usa el anotador nunca recibe nada.
 */
class RemindNotes extends Command
{
    protected $signature = 'notes:remind {--now : Avisar ya a quien tenga notas pendientes, sin mirar la hora}';

    protected $description = 'Envía un push con las notas del anotador que faltan por montar en Monday';

    public function handle(PushService $push): int
    {
        $users = User::query()
            ->when(! $this->option('now'), fn ($q) => $q->where('notes_reminder_at', now()->format('H:i')))
            ->whereNotNull('notes_reminder_at')
            ->has('pushSubscriptions')
            ->withCount(['notes as pending_notes' => fn ($q) => $q->pending()])
            ->get()
            ->where('pending_notes', '>', 0);

        foreach ($users as $user) {
            $clients = $user->notes()->pending()->whereNotNull('client')
                ->oldest()->pluck('client')->unique()->take(3);

            $count = $user->pending_notes;
            $body = ($count === 1 ? 'Te queda 1 nota' : "Te quedan {$count} notas").' por montar'
                .($clients->isNotEmpty() ? ': '.$clients->implode(', ').($count > $clients->count() ? '…' : '') : '.');

            $push->sendToUser($user, '📝 Notas para Monday', $body, [
                'url' => '/herramientas/notas/monday',
                'tag' => 'notes',
            ]);
        }

        if ($this->option('now')) {
            $this->info("Aviso enviado a {$users->count()} persona(s).");
        }

        return self::SUCCESS;
    }
}
