// Paleta de acentos reutilizable para miembros, deudas y metas.
// Se declaran las clases completas para que Tailwind no las purgue.

export type AccentKey =
    | 'primary' | 'rose' | 'emerald' | 'sky' | 'amber'
    | 'pink' | 'slate' | 'danger' | 'success' | 'indigo';

interface Accent {
    text: string;
    bgSoft: string;
    ring: string;      // color usado en el stroke del anillo (hex)
    gradient: string;  // clases from/to para fondos
    dot: string;
}

export const ACCENTS: Record<AccentKey, Accent> = {
    primary: { text: 'text-violet-600', bgSoft: 'bg-violet-50 dark:bg-violet-500/10', ring: '#7c3aed', gradient: 'from-violet-500 to-purple-600', dot: 'bg-violet-500' },
    indigo:  { text: 'text-indigo-600', bgSoft: 'bg-indigo-50 dark:bg-indigo-500/10', ring: '#6366f1', gradient: 'from-indigo-500 to-blue-600', dot: 'bg-indigo-500' },
    rose:    { text: 'text-rose-600',   bgSoft: 'bg-rose-50 dark:bg-rose-500/10',     ring: '#f43f5e', gradient: 'from-rose-500 to-pink-600',   dot: 'bg-rose-500' },
    danger:  { text: 'text-rose-600',   bgSoft: 'bg-rose-50 dark:bg-rose-500/10',     ring: '#e11d48', gradient: 'from-rose-500 to-red-600',    dot: 'bg-rose-500' },
    pink:    { text: 'text-pink-600',   bgSoft: 'bg-pink-50 dark:bg-pink-500/10',     ring: '#ec4899', gradient: 'from-pink-500 to-fuchsia-600',dot: 'bg-pink-500' },
    emerald: { text: 'text-emerald-600',bgSoft: 'bg-emerald-50 dark:bg-emerald-500/10',ring: '#10b981',gradient: 'from-emerald-500 to-green-600',dot: 'bg-emerald-500' },
    success: { text: 'text-emerald-600',bgSoft: 'bg-emerald-50 dark:bg-emerald-500/10',ring: '#16a34a',gradient: 'from-emerald-500 to-teal-600', dot: 'bg-emerald-500' },
    sky:     { text: 'text-sky-600',    bgSoft: 'bg-sky-50 dark:bg-sky-500/10',       ring: '#0ea5e9', gradient: 'from-sky-500 to-cyan-600',    dot: 'bg-sky-500' },
    amber:   { text: 'text-amber-600',  bgSoft: 'bg-amber-50 dark:bg-amber-500/10',   ring: '#f59e0b', gradient: 'from-amber-500 to-orange-600',dot: 'bg-amber-500' },
    slate:   { text: 'text-slate-600',  bgSoft: 'bg-slate-100 dark:bg-slate-500/10',  ring: '#64748b', gradient: 'from-slate-500 to-slate-700', dot: 'bg-slate-500' },
};

export function accent(key?: string | null): Accent {
    return ACCENTS[(key as AccentKey)] ?? ACCENTS.primary;
}

// Colores para gráficas (secuencia agradable y accesible)
export const CHART_COLORS = [
    '#7c3aed', '#0ea5e9', '#f59e0b', '#ec4899', '#16a34a',
    '#64748b', '#f43f5e', '#8b5cf6', '#14b8a6', '#eab308',
];
