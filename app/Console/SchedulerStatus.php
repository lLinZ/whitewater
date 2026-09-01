<?php

namespace App\Console;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Rastro de que las tareas programadas están vivas.
 *
 * Son dos señales distintas y confundirlas manda a buscar en el lugar
 * equivocado:
 *
 * - El **latido** se escribe cada minuto, así que a los 60 segundos de poner el
 *   cron ya confirma que algo llama a `schedule:run`.
 * - El **recordatorio** solo se marca cuando `routines:remind` se ejecuta de
 *   verdad, y eso pasa una vez al día. Que no se haya enviado todavía no
 *   significa que falte el cron: puede que simplemente no sean las 20:00.
 */
class SchedulerStatus
{
    private const HEARTBEAT_KEY = 'scheduler.heartbeat';

    private const REMINDER_KEY = 'routines.remind.last_run';

    /** Lo llama el scheduler cada minuto. */
    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());
    }

    /** Lo llama `routines:remind` al arrancar. */
    public static function reminderRan(): void
    {
        Cache::forever(self::REMINDER_KEY, now()->toIso8601String());
    }

    public static function lastBeat(): ?Carbon
    {
        return self::read(self::HEARTBEAT_KEY);
    }

    public static function lastReminder(): ?Carbon
    {
        return self::read(self::REMINDER_KEY);
    }

    /**
     * ¿Está corriendo el scheduler? Se da margen de 5 minutos: un latido de
     * hace media hora significa que el cron se cayó, no que esté funcionando.
     */
    public static function isAlive(): bool
    {
        return self::lastBeat()?->greaterThan(now()->subMinutes(5)) ?? false;
    }

    /** Solo para las pruebas: deja el estado como recién instalado. */
    public static function forget(): void
    {
        Cache::forget(self::HEARTBEAT_KEY);
        Cache::forget(self::REMINDER_KEY);
    }

    private static function read(string $key): ?Carbon
    {
        $stamp = Cache::get($key);

        return $stamp ? Carbon::parse($stamp) : null;
    }
}
