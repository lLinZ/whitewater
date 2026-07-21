import { Head, Link, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Plus, ShoppingCart, ArrowUp, ArrowDown, Trash2, Receipt } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import RatesCard from '@/Components/ui/RatesCard';
import { Card, EmptyState, MemberBadge } from '@/Components/ui/primitives';
import { formatMoney, formatBs, formatDate, convertUsd } from '@/lib/format';
import { PageProps, ShoppingTrip } from '@/types';

export default function MarketIndex({ trips }: { trips: ShoppingTrip[] }) {
    const { rates } = usePage<PageProps>().props;

    const startTrip = () => router.post('/mercado', {});

    const del = (t: ShoppingTrip) => {
        if (confirm(`¿Eliminar "${t.name}"?`)) router.delete(`/mercado/${t.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout title="Mercado" subtitle="Compras y conversión">
            <Head title="Mercado" />

            <RatesCard rates={rates} />

            <button
                onClick={startTrip}
                className="mt-3 flex w-full items-center justify-center gap-2 rounded-3xl bg-gradient-to-br from-violet-500 to-purple-600 py-4 font-semibold text-white shadow-card active:scale-[0.99]"
            >
                <Plus size={20} /> Empezar mercado
            </button>

            <div className="mt-4 flex flex-col gap-2">
                {trips.length === 0 && (
                    <EmptyState emoji="🛒" title="Sin mercados aún" hint="Empieza uno y ve agregando productos con su precio en dólares." />
                )}
                {trips.map((t, i) => {
                    const conv = convertUsd(t.total_usd, t.rates);
                    const prev = trips[i + 1]; // el inmediatamente anterior (más viejo)
                    const delta = prev ? t.total_usd - prev.total_usd : null;
                    return (
                        <motion.div key={t.id} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.04 }}>
                            <Card onClick={() => router.visit(`/mercado/${t.id}`)}>
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex items-center gap-3">
                                        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                            <ShoppingCart size={20} />
                                        </span>
                                        <div>
                                            <p className="font-semibold">{t.name}</p>
                                            <p className="text-xs text-default-400">
                                                {formatDate(t.created_at)} · {t.item_count} {t.item_count === 1 ? 'producto' : 'productos'}
                                                {t.status === 'active' && <span className="ml-1 text-emerald-500">· activo</span>}
                                                {t.pending_price_count > 0 && <span className="ml-1 text-amber-500">· {t.pending_price_count} sin precio</span>}
                                                {t.has_expense && <Receipt size={11} className="ml-1 inline text-default-400" />}
                                            </p>
                                        </div>
                                    </div>
                                    <button onClick={(e) => { e.stopPropagation(); del(t); }} className="text-default-300 active:text-rose-500">
                                        <Trash2 size={16} />
                                    </button>
                                </div>
                                <div className="mt-3 flex items-end justify-between">
                                    <div>
                                        <p className="text-2xl font-bold tracking-tight">{formatMoney(t.total_usd)}</p>
                                        <p className="text-xs text-default-400">{formatBs(conv.bcv)} · {formatBs(conv.usdt)} (USDT)</p>
                                    </div>
                                    {delta !== null && Math.abs(delta) > 0.005 && (
                                        <span className={`flex items-center gap-0.5 rounded-full px-2 py-1 text-xs font-semibold ${
                                            delta > 0 ? 'bg-rose-100 text-rose-600 dark:bg-rose-500/15' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15'
                                        }`}>
                                            {delta > 0 ? <ArrowUp size={12} /> : <ArrowDown size={12} />}
                                            {formatMoney(Math.abs(delta))}
                                        </span>
                                    )}
                                </div>
                            </Card>
                        </motion.div>
                    );
                })}
            </div>
        </AppLayout>
    );
}
