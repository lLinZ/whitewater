import { FormEvent, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart, Bar, PieChart, Pie, Cell, ResponsiveContainer, XAxis, Tooltip,
} from 'recharts';
import {
    Button, useDisclosure, Modal, ModalContent, ModalHeader, ModalBody, ModalFooter,
    Input, Select, SelectItem,
} from '@heroui/react';
import { Plus, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, SectionHeader, StatTile, EmptyState, MemberBadge } from '@/Components/ui/primitives';
import DecimalInput from '@/Components/ui/DecimalInput';
import ReceiptPicker from '@/Components/ui/ReceiptPicker';
import ReceiptViewer from '@/Components/ui/ReceiptViewer';
import { formatMoney, formatMoneyShort, formatDate, today } from '@/lib/format';
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
    const newExpense = useDisclosure();
    const colors = chartColors();

    const del = (id: number) => {
        if (confirm('¿Eliminar este gasto?')) router.delete(`/finanzas/gastos/${id}`, { preserveScroll: true });
    };

    return (
        <AppLayout
            title="Gastos"
            subtitle="Finanzas del hogar"
            right={<Button isIconOnly color="primary" radius="full" size="sm" onPress={newExpense.onOpen}><Plus size={18} /></Button>}
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
                            <div key={e.id} className="group flex items-center gap-3 px-4 py-3">
                                {e.receipt_url ? (
                                    <ReceiptViewer url={e.receipt_url} alt={e.description} size={36} />
                                ) : (
                                    <div className="flex h-9 w-9 items-center justify-center rounded-full bg-content2 text-sm">
                                        {e.category?.name?.[0] ?? '·'}
                                    </div>
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{e.description}</p>
                                    <p className="text-xs text-default-400">
                                        {e.category?.name ?? 'Sin categoría'} · {formatDate(e.date)}
                                    </p>
                                </div>
                                <MemberBadge member={e.creator} size={22} />
                                <span className="font-semibold">{formatMoney(e.amount)}</span>
                                <button onClick={() => del(e.id)} aria-label="Eliminar gasto" className="text-default-300 active:text-rose-500">
                                    <Trash2 size={16} />
                                </button>
                            </div>
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

            <NewExpenseModal disclosure={newExpense} categories={categories} />
        </AppLayout>
    );
}

function NewExpenseModal({ disclosure, categories }: { disclosure: ReturnType<typeof useDisclosure>; categories: ExpenseCategory[] }) {
    const empty = { amount: '', description: '', expense_category_id: '', date: today() };
    const [form, setForm] = useState(empty);
    const [receipt, setReceipt] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        // forceFormData: la foto necesita multipart, no JSON.
        router.post('/finanzas/gastos', { ...form, receipt }, {
            forceFormData: true,
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => { setForm(empty); setReceipt(null); setErrors({}); disclosure.onClose(); },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Modal isOpen={disclosure.isOpen} onClose={disclosure.onClose} placement="center" backdrop="blur" size="sm">
            <ModalContent>
                <form onSubmit={submit}>
                    <ModalHeader>Nuevo gasto</ModalHeader>
                    <ModalBody className="gap-3">
                        <DecimalInput autoFocus label="Monto" startContent="$" size="lg"
                            value={form.amount} onValueChange={(v) => setForm({ ...form, amount: v })}
                            isInvalid={!!errors.amount} errorMessage={errors.amount} isRequired />
                        <Input label="Descripción" placeholder="Mercado del mes"
                            value={form.description} onValueChange={(v) => setForm({ ...form, description: v })}
                            isInvalid={!!errors.description} errorMessage={errors.description} isRequired />
                        <Select label="Categoría" selectedKeys={form.expense_category_id ? [form.expense_category_id] : []}
                            onSelectionChange={(keys) => setForm({ ...form, expense_category_id: String(Array.from(keys)[0] ?? '') })}>
                            {categories.map((c) => <SelectItem key={String(c.id)}>{c.name}</SelectItem>)}
                        </Select>
                        <Input type="date" label="Fecha" value={form.date} onValueChange={(v) => setForm({ ...form, date: v })} />
                        <ReceiptPicker
                            value={receipt}
                            onChange={setReceipt}
                            error={errors.receipt}
                            hint="Foto del recibo o la factura (opcional)"
                        />
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={disclosure.onClose}>Cancelar</Button>
                        <Button color="primary" type="submit" isLoading={processing} isDisabled={!form.amount || !form.description}>Guardar</Button>
                    </ModalFooter>
                </form>
            </ModalContent>
        </Modal>
    );
}
