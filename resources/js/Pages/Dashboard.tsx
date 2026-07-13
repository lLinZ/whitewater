import { Head, Link, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Dropdown, DropdownTrigger, DropdownMenu, DropdownItem } from '@heroui/react';
import { Flame, PiggyBank, TrendingUp, Sparkles } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Ring from '@/Components/ui/Ring';
import RatesCard from '@/Components/ui/RatesCard';
import { Card, MemberBadge } from '@/Components/ui/primitives';
import { formatMoney, formatMoneyShort, greeting } from '@/lib/format';
import { accent } from '@/lib/accent';
import { PageProps, Member } from '@/types';

interface Achievement { key: string; label: string; emoji: string; unlocked: boolean }
interface Goal { id: number; name: string; emoji: string; color: string; progress: number; current: number; target: number }

interface Props {
    greetingName: string;
    members: Member[];
    todayMenu: { id: number; meal_type: string; recipe: string | null; is_deducted: boolean }[];
    weekTotal: number;
    savings: { total: number; target: number; goals: Goal[] };
    debt: { remaining: number; count: number };
    routines: { pending: number; total: number };
    streak: number;
    achievements: Achievement[];
}

const MEAL_LABEL: Record<string, string> = { breakfast: 'Desayuno', lunch: 'Almuerzo', dinner: 'Cena' };
const MEAL_EMOJI: Record<string, string> = { breakfast: '🌅', lunch: '🍽️', dinner: '🌙' };

const fade = {
    hidden: { opacity: 0, y: 12 },
    show: (i: number) => ({ opacity: 1, y: 0, transition: { delay: i * 0.05, ease: [0.16, 1, 0.3, 1] } }),
};

export default function Dashboard({
    greetingName, members, todayMenu, weekTotal, savings, debt, routines, streak, achievements,
}: Props) {
    const { rates } = usePage<PageProps>().props;
    const savingsPct = savings.target > 0 ? (savings.total / savings.target) * 100 : 0;

    return (
        <AppLayout
            title={`${greeting()} 👋`}
            subtitle={greetingName}
            right={
                <Dropdown placement="bottom-end">
                    <DropdownTrigger>
                        <button className="flex -space-x-2 active:opacity-70">
                            {members.map((m) => <MemberBadge key={m.id} member={m} />)}
                        </button>
                    </DropdownTrigger>
                    <DropdownMenu aria-label="Cuenta">
                        <DropdownItem key="profile" onPress={() => router.visit('/profile')}>Perfil</DropdownItem>
                        <DropdownItem key="logout" color="danger" className="text-danger" onPress={() => router.post('/logout')}>
                            Cerrar sesión
                        </DropdownItem>
                    </DropdownMenu>
                </Dropdown>
            }
        >
            <Head title="Inicio" />

            {/* Racha */}
            <motion.div custom={0} variants={fade} initial="hidden" animate="show">
                <Card className="relative overflow-hidden bg-gradient-to-br from-violet-500 to-purple-600 text-white">
                    <div className="relative z-10 flex items-center justify-between">
                        <div>
                            <p className="text-sm opacity-90">Racha del hogar</p>
                            <p className="mt-1 text-4xl font-extrabold">{streak} {streak === 1 ? 'día' : 'días'}</p>
                            <p className="mt-1 text-xs opacity-80">
                                {streak === 0 ? 'Registra algo hoy para empezar 🔥' : '¡Sigan así! No rompan la racha 🔥'}
                            </p>
                        </div>
                        <motion.div
                            animate={{ scale: [1, 1.15, 1] }}
                            transition={{ repeat: Infinity, duration: 2 }}
                            className="text-6xl drop-shadow"
                        >
                            🔥
                        </motion.div>
                    </div>
                    <Flame className="absolute -right-6 -bottom-6 h-32 w-32 text-white/10" />
                </Card>
            </motion.div>

            {/* Tasas del día */}
            <div className="mt-3">
                <RatesCard rates={rates} />
            </div>

            {/* Resumen: semana + ahorro */}
            <div className="mt-3 grid grid-cols-2 gap-3">
                <motion.div custom={1} variants={fade} initial="hidden" animate="show">
                    <Link href="/finanzas">
                        <Card className="h-full">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-medium text-default-500">Gasto semanal</span>
                                <TrendingUp size={16} className="text-violet-500" />
                            </div>
                            <p className="mt-2 text-2xl font-bold tracking-tight">{formatMoney(weekTotal)}</p>
                            <p className="mt-1 text-xs text-default-400">Ver detalle ›</p>
                        </Card>
                    </Link>
                </motion.div>

                <motion.div custom={2} variants={fade} initial="hidden" animate="show">
                    <Link href="/dinero">
                        <Card className="flex h-full items-center gap-3">
                            <Ring value={savingsPct} size={64} stroke={8} color={accent('emerald').ring}>
                                <span className="text-sm font-bold">{Math.round(savingsPct)}%</span>
                            </Ring>
                            <div className="min-w-0">
                                <p className="text-xs font-medium text-default-500">Ahorrado</p>
                                <p className="truncate text-lg font-bold">{formatMoneyShort(savings.total)}</p>
                                <p className="truncate text-xs text-default-400">de {formatMoneyShort(savings.target)}</p>
                            </div>
                        </Card>
                    </Link>
                </motion.div>
            </div>

            {/* Menú de hoy */}
            <motion.div custom={3} variants={fade} initial="hidden" animate="show" className="mt-3">
                <Card>
                    <div className="mb-2 flex items-center justify-between">
                        <span className="font-semibold">Menú de hoy</span>
                        <Link href="/cocina/menu" className="text-xs font-medium text-primary">Planificar ›</Link>
                    </div>
                    {todayMenu.length === 0 ? (
                        <p className="py-3 text-center text-sm text-default-400">Aún no hay comidas para hoy 🍳</p>
                    ) : (
                        <div className="flex flex-col gap-2">
                            {todayMenu.map((m) => (
                                <div key={m.id} className="flex items-center gap-3 rounded-2xl bg-content2 px-3 py-2">
                                    <span className="text-xl">{MEAL_EMOJI[m.meal_type]}</span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[11px] uppercase tracking-wide text-default-400">{MEAL_LABEL[m.meal_type]}</p>
                                        <p className="truncate text-sm font-medium">{m.recipe ?? 'Sin receta'}</p>
                                    </div>
                                    {m.is_deducted && <span className="text-xs text-emerald-500">✓ hecho</span>}
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            </motion.div>

            {/* Deuda + rutinas */}
            <div className="mt-3 grid grid-cols-2 gap-3">
                <motion.div custom={4} variants={fade} initial="hidden" animate="show">
                    <Link href="/dinero">
                        <Card className="h-full">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-medium text-default-500">Deuda restante</span>
                                <PiggyBank size={16} className="text-rose-500" />
                            </div>
                            <p className="mt-2 text-2xl font-bold tracking-tight text-rose-500">{formatMoneyShort(debt.remaining)}</p>
                            <p className="mt-1 text-xs text-default-400">{debt.count} {debt.count === 1 ? 'deuda' : 'deudas'}</p>
                        </Card>
                    </Link>
                </motion.div>

                <motion.div custom={5} variants={fade} initial="hidden" animate="show">
                    <Link href="/hogar">
                        <Card className="h-full">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-medium text-default-500">Tareas de hoy</span>
                                <Sparkles size={16} className="text-amber-500" />
                            </div>
                            <p className="mt-2 text-2xl font-bold tracking-tight">
                                {routines.total - routines.pending}<span className="text-base text-default-400">/{routines.total}</span>
                            </p>
                            <p className="mt-1 text-xs text-default-400">{routines.pending} pendientes</p>
                        </Card>
                    </Link>
                </motion.div>
            </div>

            {/* Logros */}
            <motion.div custom={6} variants={fade} initial="hidden" animate="show" className="mt-3">
                <Card>
                    <div className="mb-3 flex items-center justify-between">
                        <span className="font-semibold">Logros</span>
                        <span className="text-xs text-default-400">
                            {achievements.filter((a) => a.unlocked).length}/{achievements.length}
                        </span>
                    </div>
                    <div className="grid grid-cols-4 gap-2">
                        {achievements.map((a) => (
                            <div key={a.key} className="flex flex-col items-center gap-1 text-center">
                                <div
                                    className={`flex h-14 w-14 items-center justify-center rounded-2xl text-2xl transition ${
                                        a.unlocked ? 'bg-primary/10' : 'bg-content2 grayscale opacity-40'
                                    }`}
                                >
                                    {a.emoji}
                                </div>
                                <span className="text-[10px] leading-tight text-default-500">{a.label}</span>
                            </div>
                        ))}
                    </div>
                </Card>
            </motion.div>
        </AppLayout>
    );
}
