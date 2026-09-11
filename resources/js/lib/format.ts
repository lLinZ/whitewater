import dayjs from 'dayjs';
import 'dayjs/locale/es';
import relativeTime from 'dayjs/plugin/relativeTime';
import isoWeek from 'dayjs/plugin/isoWeek';

import type { Currency, RateSnapshot } from '@/types';

dayjs.extend(relativeTime);
// isoWeek: lunes = 1 … domingo = 7, la misma numeración que usa el backend
// para los días de las rutinas.
dayjs.extend(isoWeek);
dayjs.locale('es');

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const moneyCompact = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});

export function formatMoney(value: number | string | null | undefined): string {
    const n = typeof value === 'string' ? parseFloat(value) : value ?? 0;
    return money.format(Number.isFinite(n) ? (n as number) : 0);
}

export function formatMoneyShort(value: number | string | null | undefined): string {
    const n = typeof value === 'string' ? parseFloat(value) : value ?? 0;
    return moneyCompact.format(Number.isFinite(n) ? (n as number) : 0);
}

const bs = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const eur = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2 });

export function formatBs(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return '—';
    return `Bs. ${bs.format(value)}`;
}

export function formatEur(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return '—';
    return eur.format(value);
}

/**
 * Un monto en dólares BCV, visto de las tres formas en que se paga.
 *
 * - bcv: los bolívares que salen del bolsillo (dólares × tasa BCV).
 * - usdt: esos mismos bolívares, pagados con USDT a la tasa paralela.
 * - eur: esos mismos bolívares, en euros a la tasa BCV del euro.
 *
 * USDT y euro parten de los bolívares, no del dólar: la pregunta es "¿cuánto
 * me cuesta esto si pago con USDT?", no "¿cuántos bolívares serían a otra
 * tasa?".
 */
export function convertUsd(usd: number, r?: RateSnapshot | null) {
    const bcvUsd = r?.bcv_usd ?? null;
    const parUsd = r?.parallel_usd ?? null;
    const bcvEur = r?.bcv_eur ?? null;
    return {
        usd,
        bcv: bcvUsd ? usd * bcvUsd : null,
        usdt: bcvUsd && parUsd && parUsd > 0 ? usd * (bcvUsd / parUsd) : null,
        eur: bcvUsd && bcvEur && bcvEur > 0 ? usd * (bcvUsd / bcvEur) : null,
    };
}

export function formatUsdt(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return '—';
    return `${bs.format(value)} USDT`;
}

/**
 * Pasa a dólares BCV un monto pagado en otra moneda: el camino inverso de
 * convertUsd, y la misma cuenta que ExchangeRate::toUsd en el servidor.
 * null si falta la tasa que hace falta.
 */
export function toUsd(amount: number, currency: Currency, r?: RateSnapshot | null): number | null {
    if (currency === 'USD') return amount;
    const bcvUsd = r?.bcv_usd ?? null;
    const bolivares = {
        VES: amount,
        USDT: r?.parallel_usd ? amount * r.parallel_usd : null,
        EUR: r?.bcv_eur ? amount * r.bcv_eur : null,
    }[currency];
    return bolivares !== null && bcvUsd ? bolivares / bcvUsd : null;
}

/** Un monto en su moneda, con el formato de esa moneda. */
export function formatIn(value: number | null | undefined, currency: Currency): string {
    switch (currency) {
        case 'VES': return formatBs(value);
        case 'USDT': return formatUsdt(value);
        case 'EUR': return formatEur(value);
        default: return value === null || value === undefined ? '—' : formatMoney(value);
    }
}

/**
 * Lo pagado visto en las cuatro monedas, con las tasas congeladas del gasto.
 *
 * La moneda en que se pagó muestra lo anotado tal cual: recalcularla desde
 * los dólares, ya redondeados a céntimos, movería unos bolívares.
 */
export function paidEquivalents(paid: {
    amount: number | string;
    currency: Currency;
    original_amount: number | string;
    rates: RateSnapshot | null;
}) {
    const eq = convertUsd(Number(paid.amount), paid.rates);
    const original = Number(paid.original_amount);
    const key = ({ USD: 'usd', VES: 'bcv', USDT: 'usdt', EUR: 'eur' } as const)[paid.currency];
    return { ...eq, [key]: original };
}

/**
 * Deja solo dígitos y UN separador decimal, aceptando coma o punto.
 * En iPhone (teclado en español) la tecla decimal es una coma, y un
 * <input type="number"> la descarta silenciosamente: por eso los campos de
 * montos son type="text" + inputMode="decimal" y pasan por aquí.
 */
export function sanitizeDecimal(raw: string): string {
    const cleaned = raw.replace(/,/g, '.').replace(/[^\d.]/g, '');
    const [head, ...rest] = cleaned.split('.');
    return rest.length ? `${head}.${rest.join('')}` : head;
}

/** Número a partir de lo que escribió el usuario ('' o basura → null). */
export function parseDecimal(raw: string | null | undefined): number | null {
    if (raw === null || raw === undefined || raw === '') return null;
    const n = parseFloat(sanitizeDecimal(String(raw)));
    return Number.isFinite(n) ? n : null;
}

export function fromNow(date: string | Date): string {
    return dayjs(date).fromNow();
}

export function formatDate(date: string | Date, fmt = 'D MMM'): string {
    return dayjs(date).format(fmt);
}

export function today(): string {
    return dayjs().format('YYYY-MM-DD');
}

export function greeting(): string {
    const h = new Date().getHours();
    if (h < 6) return 'Buenas noches';
    if (h < 12) return 'Buenos días';
    if (h < 19) return 'Buenas tardes';
    return 'Buenas noches';
}

export { dayjs };
