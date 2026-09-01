// Paleta de acentos reutilizable para miembros, deudas y metas.
//
// Las claves son las mismas que las del color de la app (ver lib/theme.ts),
// así el color que elige un miembro en su perfil sirve tanto para teñir la app
// como para identificarlo en las listas.
//
// Las clases se declaran completas para que Tailwind no las purgue.

import { ThemeColor, accentHex, resolveColor } from '@/lib/theme';

export type AccentKey = ThemeColor;

interface Accent {
    text: string;
    bgSoft: string;
    ring: string;      // color usado en el stroke del anillo (hex)
    gradient: string;  // clases from/to para fondos
    dot: string;
}

export const ACCENTS: Record<AccentKey, Accent> = {
    violet:  { text: 'text-violet-600',  bgSoft: 'bg-violet-50 dark:bg-violet-500/10',   ring: '#7c3aed', gradient: 'from-violet-500 to-purple-600',   dot: 'bg-violet-500' },
    indigo:  { text: 'text-indigo-600',  bgSoft: 'bg-indigo-50 dark:bg-indigo-500/10',   ring: '#4f46e5', gradient: 'from-indigo-500 to-blue-600',     dot: 'bg-indigo-500' },
    blue:    { text: 'text-blue-600',    bgSoft: 'bg-blue-50 dark:bg-blue-500/10',       ring: '#2563eb', gradient: 'from-blue-500 to-sky-600',        dot: 'bg-blue-500' },
    teal:    { text: 'text-teal-600',    bgSoft: 'bg-teal-50 dark:bg-teal-500/10',       ring: '#0d9488', gradient: 'from-teal-500 to-cyan-600',       dot: 'bg-teal-500' },
    emerald: { text: 'text-emerald-600', bgSoft: 'bg-emerald-50 dark:bg-emerald-500/10', ring: '#059669', gradient: 'from-emerald-500 to-green-600',   dot: 'bg-emerald-500' },
    amber:   { text: 'text-amber-600',   bgSoft: 'bg-amber-50 dark:bg-amber-500/10',     ring: '#d97706', gradient: 'from-amber-400 to-orange-500',    dot: 'bg-amber-500' },
    orange:  { text: 'text-orange-600',  bgSoft: 'bg-orange-50 dark:bg-orange-500/10',   ring: '#ea580c', gradient: 'from-orange-500 to-red-500',      dot: 'bg-orange-500' },
    rose:    { text: 'text-rose-600',    bgSoft: 'bg-rose-50 dark:bg-rose-500/10',       ring: '#e11d48', gradient: 'from-rose-500 to-red-600',        dot: 'bg-rose-500' },
    pink:    { text: 'text-pink-600',    bgSoft: 'bg-pink-50 dark:bg-pink-500/10',       ring: '#db2777', gradient: 'from-pink-500 to-fuchsia-600',    dot: 'bg-pink-500' },
    slate:   { text: 'text-slate-600',   bgSoft: 'bg-slate-100 dark:bg-slate-500/10',    ring: '#475569', gradient: 'from-slate-500 to-slate-700',     dot: 'bg-slate-500' },
};

/**
 * Nombres viejos que siguen guardados en la base (deudas creadas con 'danger',
 * miembros con 'primary'…). Se traducen en vez de migrarlos: son etiquetas de
 * color, no datos con significado propio.
 */
const ALIASES: Record<string, AccentKey> = {
    primary: 'violet',
    sky: 'blue',
    danger: 'rose',
    success: 'emerald',
    default: 'slate',
};

export function accent(key?: string | null): Accent {
    return ACCENTS[normalizeAccent(key)];
}

export function normalizeAccent(key?: string | null): AccentKey {
    if (key && key in ALIASES) return ALIASES[key];
    return resolveColor(key);
}

/**
 * Colores para gráficas. El primero es el acento vigente del miembro, así las
 * barras y el donut van a juego con el resto de la app.
 */
export function chartColors(): string[] {
    return [accentHex(), '#0ea5e9', '#f59e0b', '#ec4899', '#16a34a', '#64748b', '#f43f5e', '#8b5cf6', '#14b8a6', '#eab308'];
}
