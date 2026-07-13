import dayjs from 'dayjs';
import 'dayjs/locale/es';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);
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

export interface RateLike {
    bcv_usd: number | null;
    parallel_usd: number | null;
    bcv_eur: number | null;
}

/** Convierte un monto en USD a Bs (BCV), Bs (USDT/paralelo) y EUR. */
export function convertUsd(usd: number, r?: RateLike | null) {
    const bcvUsd = r?.bcv_usd ?? null;
    const parUsd = r?.parallel_usd ?? null;
    const bcvEur = r?.bcv_eur ?? null;
    return {
        usd,
        bcv: bcvUsd ? usd * bcvUsd : null,
        usdt: parUsd ? usd * parUsd : null,
        eur: bcvUsd && bcvEur && bcvEur > 0 ? usd * (bcvUsd / bcvEur) : null,
    };
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
