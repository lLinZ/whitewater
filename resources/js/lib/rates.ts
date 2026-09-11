import { useEffect, useState } from 'react';
import axios from 'axios';
import type { RateSnapshot } from '@/types';

/** Tasas vigentes un día; `date` es el día del que son de verdad. */
export interface DayRates extends RateSnapshot {
    date: string;
}

// Una petición por día: cambiar la fecha adelante y atrás no vuelve a pedir.
const cache = new Map<string, Promise<DayRates | null>>();

function load(date: string): Promise<DayRates | null> {
    if (!cache.has(date)) {
        cache.set(
            date,
            axios.get<{ rates: DayRates | null }>('/tasas/dia', { params: { fecha: date } })
                .then((res) => res.data.rates)
                // Un fallo de red no se queda en caché: el siguiente intento vuelve a pedir.
                .catch(() => { cache.delete(date); return null; }),
        );
    }
    return cache.get(date)!;
}

/**
 * Las tasas del día elegido, las mismas con que el servidor convertirá al
 * guardar. Sirve para enseñar la conversión mientras se escribe el monto.
 */
export function useRatesOn(date: string): { rates: DayRates | null; loading: boolean } {
    const [state, setState] = useState<{ date: string; rates: DayRates | null } | null>(null);

    useEffect(() => {
        if (!date) return;
        let current = true;
        load(date).then((rates) => { if (current) setState({ date, rates }); });
        return () => { current = false; };
    }, [date]);

    const ready = state?.date === date;
    return { rates: ready ? state.rates : null, loading: !!date && !ready };
}
