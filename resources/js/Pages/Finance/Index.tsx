import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart, Bar, PieChart, Pie, Cell, ResponsiveContainer, XAxis, Tooltip,
} from 'recharts';
import { Button, useDisclosure } from '@heroui/react';
import { Plus, Tags, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, SectionHeader, StatTile, EmptyState, MemberBadge } from '@/Components/ui/primitives';
import CategoryManager from '@/Components/ui/CategoryManager';
import ExpenseModal from '@/Components/ui/ExpenseModal';
import ReceiptViewer from '@/Components/ui/ReceiptViewer';
import { formatMoney, formatMoneyShort, formatDate } from '@/lib/format';
import { chartColors } from '@/lib/accent';
import { Expense, ExpenseCategory } from '@/types';

interface Props {
    categories: ExpenseCategory[];
    expenses: Expense[];
    expenseCount: number;
    stats: { weekTotal: number; monthTotal: number };
    byCategory: { name: string; total: number }[];
    weeklyTrend: { label: string; total: number }[];
}

export default function FinanceIndex({ categories, expenses, expenseCount, stats, byCategory, weeklyTrend }: Props) {
    const modal = useDisclosure();
    const categoryManager = useDisclosure();
    // null = el modal está creando; con un gasto dentro, está editando ese.
    const [editing, setEditing] = useState<Expense | null>(null);
    const colors = chartColors();

    const openNew = () => { setEditing(null); modal.onOpen(); };
    const openEdit = (expense: Expense) => { setEditing(expense); modal.onOpen(); };

    const del = (expense: Expense) => {
        if (confirm(`¿Eliminar "${expense.description}"?`)) {
            router.delete(`/finanzas/gastos/${expense.id}`, { preserveScroll: true });
        }
    };

    return (
        <AppLayout
            title="Gastos"
            subtitle="Finanzas del hogar"
            right={
                <div className="flex items-center gap-1">
                    <Button
                        isIconOnly variant="light" radius="full" size="sm"
                        aria-label="Categorías" onPress={categoryManager.onOpen}
                    >
                        <Tags size={17} className="text-default-400" />
                    </Button>
                    <Button isIconOnly color="primary" radius="full" size="sm" aria-label="Nuevo gasto" onPress={openNew}>
                        <Plus size={18} />
                    </Button>
                </div>
            }
        >
            <Head title="Gastos" />

            <div className="grid grid-cols-2 gap-3">
                <StatTile label="Esta semana" value={formatMoney(stats.weekTotal)} tone="violet" />
                <StatTile label="Este mes" value={formatMoney(stats.monthTotal)} tone="blue" />
            </div>

            {/* Tendencia semanal */}
            <SectionHeader title="Últimas 6 semanas" />
            <Card>
                <ResponsiveContainer width="100%" height={170}>
                    <BarChart data={weeklyTrend} margin={{ top: 8, right: 4, left: 4, bottom: 0 }}>
                        <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={11} stroke="#a1a1aa" />
                        <Tooltip
                            cursor={{ fill: 'rgba(127,127,127,0.08)' }}
                            formatter={(v) => [formatMoney(v as number), 'Total']}
                            contentStyle={{ borderRadius: 14, border: 'none', boxShadow: '0 8px 30px -6px rgba(16,24,40,0.14)', fontSize: 13 }}
                        />
                        <Bar dataKey="total" radius={[8, 8, 8, 8]} fill="var(--app-accent, #7c3aed)" maxBarSize={34} />
                    </BarChart>
                </ResponsiveContainer>
            </Card>

            {/* Por categoría */}
            {byCategory.length > 0 && (
                <>
                    <SectionHeader title="Por categoría (este mes)" />
                    <Card>
                        <div className="flex items-center gap-4">
                            <ResponsiveContainer width={130} height={130}>
                                <PieChart>
                                    <Pie data={byCategory} dataKey="total" nameKey="name" innerRadius={38} outerRadius={62} paddingAngle={2} stroke="none">
                                        {byCategory.map((_, i) => <Cell key={i} fill={colors[i % colors.length]} />)}
                                    </Pie>
                                    <Tooltip formatter={(v) => formatMoney(v as number)} contentStyle={{ borderRadius: 14, border: 'none', fontSize: 13 }} />
                                </PieChart>
                            </ResponsiveContainer>
                            <div className="flex-1 space-y-1.5">
                                {byCategory.map((c, i) => (
                                    <div key={c.name} className="flex items-center gap-2 text-sm">
                                        <span className="h-2.5 w-2.5 rounded-full" style={{ background: colors[i % colors.length] }} />
                                        <span className="flex-1 truncate text-default-600">{c.name}</span>
                                        <span className="font-medium">{formatMoneyShort(c.total)}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </Card>
                </>
            )}

            {/* Movimientos */}
            <SectionHeader
                title="Movimientos recientes"
                action={
                    expenseCount > 0 && (
                        <Link href="/finanzas/historial" className="text-xs font-medium text-primary active:opacity-60">
                            Ver los {expenseCount} ›
                        </Link>
                    )
                }
            />
            {expenses.length === 0 ? (
                <EmptyState emoji="🧾" title="Sin gastos aún" hint="Toca el botón + para registrar tu primer gasto." />
            ) : (
                <>
                    <Card className="divide-y divide-divider !p-0">
                        {expenses.map((e) => (
                            <ExpenseRow key={e.id} expense={e} onEdit={openEdit} onDelete={del} />
                        ))}
                    </Card>

                    {expenseCount > expenses.length && (
                        <Link href="/finanzas/historial" className="mt-3 block">
                            <Button fullWidth variant="flat" radius="full">
                                Ver historial completo ({expenseCount})
                            </Button>
                        </Link>
                    )}
                </>
            )}

            <ExpenseModal
                isOpen={modal.isOpen}
                onClose={modal.onClose}
                categories={categories}
                expense={editing}
            />

            <CategoryManager
                isOpen={categoryManager.isOpen}
                onClose={categoryManager.onClose}
                categories={categories}
            />
        </AppLayout>
    );
}

/** Tocar la fila abre la edición; ahí es donde se le adjunta la factura. */
function ExpenseRow({
    expense, onEdit, onDelete,
}: {
    expense: Expense;
    onEdit: (expense: Expense) => void;
    onDelete: (expense: Expense) => void;
}) {
    return (
        <div className="flex items-center gap-3 px-4 py-3">
            {expense.receipt_url ? (
                <ReceiptViewer url={expense.receipt_url} alt={expense.description} size={36} />
            ) : (
                <div className="flex h-9 w-9 items-center justify-center rounded-full bg-content2 text-sm">
                    {expense.category?.name?.[0] ?? '·'}
                </div>
            )}
            <button onClick={() => onEdit(expense)} className="min-w-0 flex-1 text-left active:opacity-60">
                <p className="truncate text-sm font-medium">{expense.description}</p>
                <p className="text-xs text-default-400">
                    {expense.category?.name ?? 'Sin categoría'} · {formatDate(expense.date)}
                    {!expense.receipt_url && ' · sin comprobante'}
                </p>
            </button>
            <MemberBadge member={expense.creator} size={22} />
            <button onClick={() => onEdit(expense)} className="shrink-0 font-semibold active:opacity-60">
                {formatMoney(expense.amount)}
            </button>
            <button onClick={() => onDelete(expense)} aria-label="Eliminar gasto" className="shrink-0 text-default-300 active:text-rose-500">
                <Trash2 size={16} />
            </button>
        </div>
    );
}
