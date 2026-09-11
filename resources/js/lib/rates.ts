import { useEffect, useState } from 'react';
import axios from 'axios';
import type { RateSnapshot } from '@/types';

/** Tasas vigentes un día; `date` es el día del que son de verdad. */
export interface DayRates extends RateSnapshot {
    date: string;
}

// Una petición por día: cambiar la fecha adelante y atrás no vuelve a pedir.
// Solo se guardan las respuestas buenas; un fallo se reintenta la próxima vez.
const cache = new Map<string, Promise<DayRates | null>>();

function load(date: string): Promise<DayRates | null> {
    if (!cache.has(date)) {
        const request = axios.get('/tasas/dia', { params: { fecha: date } }).then((res) => {
            // Una redirección al login o una página de error llegan como HTML
            // con código 200: sin la clave `rates` no es una respuesta de tasas.
            if (!res.data || typeof res.data !== 'object' || !('rates' in res.data)) {
                throw new Error('Respuesta inesperada de /tasas/dia');
            }
            return res.data.rates as DayRates | null;
        });
        request.catch(() => cache.delete(date));
        cache.set(date, request);
    }
    return cache.get(date)!;
}

/**
 * Las tasas del día elegido, las mismas con que el servidor convertirá al
 * guardar. Sirve para enseñar la conversión mientras se escribe el monto.
 *
 * `rates: null` con `failed: false` es que de verdad no hay tasas guardadas;
 * `failed` es que no se pudo preguntar. Son cosas distintas: en el segundo
 * caso el servidor sí puede convertir al guardar.
 */
export function useRatesOn(date: string): { rates: DayRates | null; loading: boolean; failed: boolean } {
    const [state, setState] = useState<{ date: string; rates: DayRates | null; failed: boolean } | null>(null);

    useEffect(() => {
        if (!date) return;
        let current = true;
        load(date).then(
            (rates) => { if (current) setState({ date, rates, failed: false }); },
            () => { if (current) setState({ date, rates: null, failed: true }); },
        );
        return () => { current = false; };
    }, [date]);

    const ready = state?.date === date;
    return {
        rates: ready ? state.rates : null,
        loading: !!date && !ready,
        failed: ready && state.failed,
    };
}
