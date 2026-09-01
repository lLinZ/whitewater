import { ReactNode, useEffect, useRef, useState } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import confetti from 'canvas-confetti';
import { Home, UtensilsCrossed, ShoppingCart, Wallet, Target, Sparkles, CheckCircle2, XCircle } from 'lucide-react';
import { accentScale, applyTheme, watchSystemTheme } from '@/lib/theme';
import { PageProps } from '@/types';

interface AppLayoutProps {
    children: ReactNode;
    title?: string;
    subtitle?: string;
    right?: ReactNode;
    back?: string;
    /** El perfil no necesita un atajo a sí mismo. */
    hideAvatar?: boolean;
}

const NAV = [
    { href: '/dashboard', label: 'Inicio', icon: Home, match: ['/dashboard'] },
    { href: '/cocina/menu', label: 'Menú', icon: UtensilsCrossed, match: ['/cocina'] },
    { href: '/mercado', label: 'Mercado', icon: ShoppingCart, match: ['/mercado'] },
    { href: '/finanzas', label: 'Gastos', icon: Wallet, match: ['/finanzas'] },
    { href: '/dinero', label: 'Metas', icon: Target, match: ['/dinero'] },
    { href: '/hogar', label: 'Hogar', icon: Sparkles, match: ['/hogar'] },
];

function fireConfetti(colors: string[]) {
    const shoot = (particleRatio: number, opts: confetti.Options) =>
        confetti({
            ...opts,
            origin: { y: 0.7 },
            particleCount: Math.floor(200 * particleRatio),
            colors,
        });
    shoot(0.25, { spread: 26, startVelocity: 55 });
    shoot(0.2, { spread: 60 });
    shoot(0.35, { spread: 100, decay: 0.91, scalar: 0.8 });
    shoot(0.1, { spread: 120, startVelocity: 25, decay: 0.92, scalar: 1.2 });
}

export default function AppLayout({ children, title, subtitle, right, back, hideAvatar = false }: AppLayoutProps) {
    const { url, props } = usePage<PageProps>();
    const flash = props.flash;
    const user = props.auth?.user;
    const [toast, setToast] = useState<{ msg: string; variant: 'success' | 'error' } | null>(null);
    const lastFlash = useRef<string>('');

    // El color y el modo viven en el perfil: al cambiarlos (o al entrar desde
    // otro dispositivo) la app se repinta sin recargar.
    useEffect(() => {
        applyTheme(user?.color, user?.theme);
        return watchSystemTheme(user?.color, user?.theme);
    }, [user?.color, user?.theme]);

    useEffect(() => {
        const stamp = `${flash?.success ?? ''}|${flash?.error ?? ''}|${flash?.celebrate ?? ''}`;
        if (stamp === '|' || stamp === lastFlash.current) return;
        lastFlash.current = stamp;

        if (flash?.celebrate) {
            const scale = accentScale(user?.color);
            fireConfetti([scale[5], scale[3], '#f59e0b', '#10b981', '#0ea5e9']);
            setToast({ msg: flash.celebrate, variant: 'success' });
        } else if (flash?.success) {
            setToast({ msg: flash.success, variant: 'success' });
        } else if (flash?.error) {
            setToast({ msg: flash.error, variant: 'error' });
        }
    }, [flash, user?.color]);

    useEffect(() => {
        if (!toast) return;
        const t = setTimeout(() => setToast(null), 2600);
        return () => clearTimeout(t);
    }, [toast]);

    return (
        <div className="min-h-[100dvh] bg-background text-foreground">
            {/* Top bar */}
            <header className="glass sticky top-0 z-30 border-b border-divider pt-safe">
                <div className="mx-auto flex h-14 max-w-2xl items-center gap-3 px-4">
                    {back && (
                        <button
                            onClick={() => router.visit(back)}
                            className="text-primary text-sm font-medium active:opacity-60"
                        >
                            ‹ Atrás
                        </button>
                    )}
                    <div className="min-w-0 flex-1">
                        {title && <h1 className="truncate text-[17px] font-semibold leading-tight">{title}</h1>}
                        {subtitle && <p className="truncate text-xs text-default-500">{subtitle}</p>}
                    </div>
                    {right}
                    {!hideAvatar && user && (
                        <Link href="/profile" aria-label="Tu perfil" className="shrink-0 active:opacity-70">
                            {user.avatar_url ? (
                                <img
                                    src={user.avatar_url}
                                    alt={user.name}
                                    className="h-8 w-8 rounded-full object-cover ring-2 ring-primary/25"
                                />
                            ) : (
                                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-base ring-2 ring-primary/25">
                                    {user.avatar_emoji ?? '🙂'}
                                </span>
                            )}
                        </Link>
                    )}
                </div>
            </header>

            {/* Content */}
            <main className="mx-auto max-w-2xl px-4 pb-32 pt-4">{children}</main>

            {/* Bottom nav */}
            <nav className="glass fixed inset-x-0 bottom-0 z-30 border-t border-divider pb-safe">
                <div className="mx-auto flex max-w-2xl items-stretch justify-around px-2">
                    {NAV.map((item) => {
                        const active = item.match.some((m) => url.startsWith(m));
                        const Icon = item.icon;
                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className="relative flex flex-1 flex-col items-center gap-0.5 py-2 no-select"
                            >
                                {active && (
                                    <motion.span
                                        layoutId="nav-pill"
                                        className="absolute inset-x-1.5 top-1 h-9 rounded-2xl bg-primary/10"
                                        transition={{ type: 'spring', stiffness: 500, damping: 40 }}
                                    />
                                )}
                                <Icon
                                    size={21}
                                    className={`relative z-10 transition-colors ${active ? 'text-primary' : 'text-default-400'}`}
                                    strokeWidth={active ? 2.4 : 2}
                                />
                                <span
                                    className={`relative z-10 text-[9px] font-medium transition-colors ${active ? 'text-primary' : 'text-default-400'}`}
                                >
                                    {item.label}
                                </span>
                            </Link>
                        );
                    })}
                </div>
            </nav>

            {/* Toast */}
            <AnimatePresence>
                {toast && (
                    <motion.div
                        initial={{ opacity: 0, y: -20, scale: 0.95 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: -20, scale: 0.95 }}
                        className="fixed inset-x-0 top-0 z-50 flex justify-center px-4 pt-safe"
                    >
                        <div className="mt-3 flex items-center gap-2 rounded-2xl bg-foreground px-4 py-2.5 text-background shadow-float">
                            {toast.variant === 'success' ? (
                                <CheckCircle2 size={18} className="text-emerald-400" />
                            ) : (
                                <XCircle size={18} className="text-rose-400" />
                            )}
                            <span className="text-sm font-medium">{toast.msg}</span>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}
