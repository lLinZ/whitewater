// Color de la app y modo claro/oscuro, elegidos por cada miembro.
//
// HeroUI pinta todo (`bg-primary`, `color="primary"`, anillos de foco…) a
// partir de variables CSS en HSL. Cambiarlas en el <html> repinta la app
// entera sin recompilar Tailwind: por eso el color es un ajuste del perfil y
// no algo fijo en tailwind.config.js.

export type ThemeColor =
    | 'violet' | 'indigo' | 'blue' | 'teal' | 'emerald'
    | 'amber' | 'orange' | 'rose' | 'pink' | 'slate';

export type ThemeMode = 'light' | 'dark' | 'system';

export const DEFAULT_COLOR: ThemeColor = 'violet';
export const DEFAULT_MODE: ThemeMode = 'system';

/** Escalas 50…900 (las de Tailwind), de más clara a más oscura. */
const SCALES: Record<ThemeColor, string[]> = {
    violet:  ['#f5f3ff', '#ede9fe', '#ddd6fe', '#c4b5fd', '#a78bfa', '#8b5cf6', '#7c3aed', '#6d28d9', '#5b21b6', '#4c1d95'],
    indigo:  ['#eef2ff', '#e0e7ff', '#c7d2fe', '#a5b4fc', '#818cf8', '#6366f1', '#4f46e5', '#4338ca', '#3730a3', '#312e81'],
    blue:    ['#eff6ff', '#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6', '#2563eb', '#1d4ed8', '#1e40af', '#1e3a8a'],
    teal:    ['#f0fdfa', '#ccfbf1', '#99f6e4', '#5eead4', '#2dd4bf', '#14b8a6', '#0d9488', '#0f766e', '#115e59', '#134e4a'],
    emerald: ['#ecfdf5', '#d1fae5', '#a7f3d0', '#6ee7b7', '#34d399', '#10b981', '#059669', '#047857', '#065f46', '#064e3b'],
    amber:   ['#fffbeb', '#fef3c7', '#fde68a', '#fcd34d', '#fbbf24', '#f59e0b', '#d97706', '#b45309', '#92400e', '#78350f'],
    orange:  ['#fff7ed', '#ffedd5', '#fed7aa', '#fdba74', '#fb923c', '#f97316', '#ea580c', '#c2410c', '#9a3412', '#7c2d12'],
    rose:    ['#fff1f2', '#ffe4e6', '#fecdd3', '#fda4af', '#fb7185', '#f43f5e', '#e11d48', '#be123c', '#9f1239', '#881337'],
    pink:    ['#fdf2f8', '#fce7f3', '#fbcfe8', '#f9a8d4', '#f472b6', '#ec4899', '#db2777', '#be185d', '#9d174d', '#831843'],
    slate:   ['#f8fafc', '#f1f5f9', '#e2e8f0', '#cbd5e1', '#94a3b8', '#64748b', '#475569', '#334155', '#1e293b', '#0f172a'],
};

const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];

/** Índices dentro de la escala del tono principal en cada modo. */
const MAIN_LIGHT = 6; // 600: buen contraste sobre fondo claro
const MAIN_DARK = 5;  // 500: no deslumbra sobre fondo oscuro

export const COLOR_LABELS: Record<ThemeColor, string> = {
    violet: 'Violeta', indigo: 'Índigo', blue: 'Azul', teal: 'Turquesa', emerald: 'Verde',
    amber: 'Ámbar', orange: 'Naranja', rose: 'Rojo', pink: 'Rosa', slate: 'Grafito',
};

export const COLOR_KEYS = Object.keys(SCALES) as ThemeColor[];

export const MODE_LABELS: Record<ThemeMode, string> = {
    light: 'Claro', dark: 'Oscuro', system: 'Automático',
};

export function resolveColor(key?: string | null): ThemeColor {
    return key && key in SCALES ? (key as ThemeColor) : DEFAULT_COLOR;
}

export function resolveMode(mode?: string | null): ThemeMode {
    return mode === 'light' || mode === 'dark' || mode === 'system' ? mode : DEFAULT_MODE;
}

/** Muestra de un color para los selectores del perfil. */
export function swatch(key: ThemeColor): string {
    return SCALES[key][MAIN_LIGHT];
}

/** El tono principal en hex, para lo que no pasa por CSS (SVG, gráficas). */
export function accentHex(key?: string | null, dark = isDarkNow()): string {
    return SCALES[resolveColor(key)][dark ? MAIN_DARK : MAIN_LIGHT];
}

/** Escala completa del acento vigente, para degradados y series de gráficas. */
export function accentScale(key?: string | null): string[] {
    return SCALES[resolveColor(key)];
}

function hexToHsl(hex: string): string {
    const int = parseInt(hex.slice(1), 16);
    const r = ((int >> 16) & 255) / 255;
    const g = ((int >> 8) & 255) / 255;
    const b = (int & 255) / 255;

    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const light = (max + min) / 2;
    const delta = max - min;

    let hue = 0;
    if (delta !== 0) {
        if (max === r) hue = ((g - b) / delta) % 6;
        else if (max === g) hue = (b - r) / delta + 2;
        else hue = (r - g) / delta + 4;
        hue *= 60;
        if (hue < 0) hue += 360;
    }

    const sat = delta === 0 ? 0 : delta / (1 - Math.abs(2 * light - 1));
    const round = (n: number) => Math.round(n * 100) / 100;

    return `${round(hue)} ${round(sat * 100)}% ${round(light * 100)}%`;
}

/** Luminancia relativa (WCAG), para decidir si el texto encima va blanco o negro. */
function luminance(hex: string): number {
    const int = parseInt(hex.slice(1), 16);
    const channel = (value: number) => {
        const c = value / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel((int >> 16) & 255)
        + 0.7152 * channel((int >> 8) & 255)
        + 0.0722 * channel(int & 255);
}

export function prefersDark(): boolean {
    return typeof window !== 'undefined'
        && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function isDarkNow(): boolean {
    return typeof document !== 'undefined'
        && document.documentElement.classList.contains('dark');
}

/** ¿Toca pintar oscuro? 'system' sigue al ajuste del teléfono. */
export function shouldUseDark(mode: ThemeMode): boolean {
    return mode === 'dark' || (mode === 'system' && prefersDark());
}

/** Declaraciones CSS del acento, para inyectarlas en el <html>. */
function cssVariables(color: ThemeColor, dark: boolean): Record<string, string> {
    const scale = SCALES[color];
    // En oscuro la escala se invierte: los tonos "claros" de HeroUI pasan a ser
    // los oscuros, igual que hace su tema por defecto.
    const ordered = dark ? [...scale].reverse() : scale;
    const main = scale[dark ? MAIN_DARK : MAIN_LIGHT];

    const vars: Record<string, string> = {
        '--heroui-primary': hexToHsl(main),
        // Texto encima del acento: blanco sobre los tonos oscuros, el tono
        // más oscuro de la propia escala sobre los claros (ámbar, naranja,
        // turquesa). El umbral 0.25 es el punto donde ambas opciones dan el
        // mismo contraste, así que siempre gana la más legible.
        '--heroui-primary-foreground': luminance(main) > 0.25 ? hexToHsl(scale[9]) : '0 0% 100%',
        // El anillo de foco de HeroUI es azul fijo; sin esto, un tema verde
        // seguiría enfocando en azul.
        '--heroui-focus': hexToHsl(main),
        '--app-accent': main,
        '--app-accent-soft': scale[dark ? 8 : 1],
    };

    STEPS.forEach((step, i) => {
        vars[`--heroui-primary-${step}`] = hexToHsl(ordered[i]);
    });

    return vars;
}

/** Lo que el navegador pinta detrás de la app (barra de estado del iPhone). */
const PAGE_BACKGROUND = { light: '#F5F5F7', dark: '#0B0B0F' };

const STORAGE_KEY = 'whitewater.theme';

/**
 * Aplica color y modo al documento y los recuerda.
 *
 * Lo guardado en localStorage es lo que lee el script del <head> para pintar
 * ya bien la primera pantalla: sin eso, la app abre en violeta claro y salta
 * al tema real cuando React monta.
 */
export function applyTheme(color?: string | null, mode?: string | null): void {
    if (typeof document === 'undefined') return;

    const resolvedColor = resolveColor(color);
    const resolvedMode = resolveMode(mode);
    const dark = shouldUseDark(resolvedMode);
    const root = document.documentElement;

    root.classList.toggle('dark', dark);
    root.classList.toggle('light', !dark);
    root.style.colorScheme = dark ? 'dark' : 'light';

    const vars = cssVariables(resolvedColor, dark);
    Object.entries(vars).forEach(([name, value]) => root.style.setProperty(name, value));

    document.querySelector('meta[name="theme-color"]')
        ?.setAttribute('content', dark ? PAGE_BACKGROUND.dark : PAGE_BACKGROUND.light);

    try {
        // Se guardan los dos juegos de variables ya calculados para que el
        // script del <head> no repita la paleta ni convierta colores, y para
        // que 'Automático' acierte aunque el teléfono haya cambiado de modo
        // desde la última visita.
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            mode: resolvedMode,
            light: cssVariables(resolvedColor, false),
            dark: cssVariables(resolvedColor, true),
        }));
    } catch {
        // Safari en privado no deja escribir: solo se pierde el anti-parpadeo.
    }
}

/**
 * Mantiene 'Automático' al día si el teléfono cambia de claro a oscuro
 * mientras la app está abierta. Devuelve la función para dejar de escuchar.
 */
export function watchSystemTheme(color?: string | null, mode?: string | null): () => void {
    if (typeof window === 'undefined' || resolveMode(mode) !== 'system') {
        return () => {};
    }

    const query = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => applyTheme(color, 'system');
    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
}
