<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Routine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'days' => 'array',
    ];

    /** Lunes … domingo en ISO-8601 (1..7), como Carbon::dayOfWeekIso. */
    public const DAY_LABELS = [
        1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue',
        5 => 'Vie', 6 => 'Sáb', 7 => 'Dom',
    ];

    public function logs()
    {
        return $this->hasMany(RoutineLog::class)->latest('completed_at');
    }

    /** Días seleccionados, normalizados y ordenados. */
    public function selectedDays(): array
    {
        $days = array_values(array_unique(array_map('intval', $this->days ?? [])));
        sort($days);

        return array_values(array_filter($days, fn ($d) => $d >= 1 && $d <= 7));
    }

    /**
     * ¿Toca esta rutina en esta fecha? Una rutina semanal con días elegidos
     * (p. ej. miércoles y viernes) solo toca esos días; las demás siempre
     * están abiertas dentro de su ventana.
     */
    public function isDueOn(Carbon $date): bool
    {
        $days = $this->selectedDays();

        if ($this->frequency === 'weekly' && $days) {
            return in_array($date->dayOfWeekIso, $days, true);
        }

        return true;
    }

    /**
     * Inicio de la ventana que cuenta como "ya hecha": el día para las
     * diarias y las de días fijos, la semana para las semanales sueltas y
     * el mes para las mensuales.
     */
    public function windowStart(Carbon $date): Carbon
    {
        return match ($this->frequency) {
            'monthly' => $date->copy()->startOfMonth(),
            'weekly' => $this->selectedDays() ? $date->copy()->startOfDay() : $date->copy()->startOfWeek(),
            default => $date->copy()->startOfDay(),
        };
    }

    /** ¿Ya se completó dentro de la ventana vigente? Requiere logs cargados. */
    public function isDoneOn(Carbon $date): bool
    {
        return $this->logs
            ->where('completed_at', '>=', $this->windowStart($date))
            ->isNotEmpty();
    }

    /** Toca hoy y todavía no se ha hecho. */
    public function isPendingOn(Carbon $date): bool
    {
        return $this->isDueOn($date) && ! $this->isDoneOn($date);
    }

    /** "Lun, Mié y Vie" · "Diaria" · "Mensual" */
    public function scheduleLabel(): string
    {
        $days = $this->selectedDays();

        if ($this->frequency === 'weekly' && $days) {
            $names = array_map(fn ($d) => self::DAY_LABELS[$d], $days);
            if (count($names) === 1) {
                return $names[0];
            }
            $last = array_pop($names);

            return implode(', ', $names).' y '.$last;
        }

        return match ($this->frequency) {
            'weekly' => 'Semanal',
            'monthly' => 'Mensual',
            default => 'Diaria',
        };
    }
}
