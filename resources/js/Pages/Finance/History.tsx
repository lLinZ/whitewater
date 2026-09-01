import { useEffect, useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Button, Input, Select, SelectItem, Switch, useDisclosure } from '@heroui/react';
import { Search, SlidersHorizontal, Trash2, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, EmptyState, MemberBadge, SectionHeader } from '@/Components/ui/primitives';
import ExpenseModal from '@/Components/ui/ExpenseModal';
import ReceiptViewer from '@/Components/ui/ReceiptViewer';
import { dayjs, formatDate, formatMoney, formatMoneyShort } from '@/lib/format';
import { accent } from '@/lib/accent';
import { Expense, ExpenseCategory, Member, PageProps, Paginated } from '@/types';

interface Filters {
    q: string;
    category: string;
    member: string;
    from: string;
    to: string;
    receipts: string;
}

interface Props {
    filters: Filters;
    categories: ExpenseCategory[];
    members: Member[];
    expenses: Paginated<Expense>;
    totals: { count: number; sum: number };
}

/** Atajos de fecha: lo que uno busca de verdad casi siempre es "este mes". */
const RANGES = [
    { key: 'month', label: 'Este mes', from: () => dayjs().startOf('month'), to: () => dayjs().endOf('month') },
    { key: 'last', label: 'Mes pasado', from: () => dayjs().subtract(1, 'month').startOf('month'), to: () => dayjs().subtract(1, 'month').endOf('month') },
    { key: 'year', label: 'Este año', from: () => dayjs().startOf('year'), to: () => dayjs().endOf('year') },
];

export default function FinanceHistory({ filters, categories, members, expenses, totals }: Props) {
    const user = usePage<PageProps>().props.auth.user;
    const editModal = useDisclosure();
    const [editing, setEditing] = useState<Expense | null>(null);
    const [form, setForm] = useState<Filters>(filters);
    const [showFilters, setShowFilters] = useState(
        Boolean(filters.category || filters.member || filters.from || filters.to || filters.receipts),
    );

    // La búsqueda por texto se manda sola tras una pausa: en el teléfono,
    // tener que pulsar "buscar" en cada intento es un incordio.
    useEffect(() => {
        if (form.q === filters.q) return;
        const timer = setTimeout(() => apply({ ...form }), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.q]);

    const apply = (next: Filters, page?: number) => {
        const query: Record<string, string | number> = {};
        Object.entries(next).forEach(([key, value]) => {
            if (value) query[key] = value;
        });
        if (page && page > 1) query.page = page;

        router.get('/finanzas/historial', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const set = (patch: Partial<Filters>, submit = true) => {
        const next = { ...form, ...patch };
        setForm(next);
        if (submit) apply(next);
    };

    const clear = () => {
        const empty: Filters = { q: '', category: '', member: '', from: '', to: '', receipts: '' };
        setForm(empty);
        apply(empty);
    };

    const active = useMemo(
        () => Object.entries(form).filter(([, value]) => value).length,
        [form],
    );

    const activeRange = RANGES.find(
        (r) => form.from === r.from().format('YYYY-MM-DD') && form.to === r.to().format('YYYY-MM-DD'),
    );

    return (
        <AppLayout
            title="Historial de gastos"
            subtitle={`${totals.count} ${totals.count === 1 ? 'movimiento' : 'movimientos'}`}
            back="/finanzas"
            right={
                <Button
                    isIconOnly size="sm" radius="full"
                    variant={showFilters ? 'solid' : 'light'}
                    color={showFilters ? 'primary' : 'default'}
                    aria-label="Filtros"
                    onPress={() => setShowFilters((v) => !v)}
                >
                    <SlidersHorizontal size={16} />
                </Button>
            }
        >
            <Head title="Historial de gastos" />

            {/* Total de lo filtrado */}
            <Card className={`bg-gradient-to-br text-white ${accent(user.color).gradient}`}>
                <p className="text-xs opacity-90">
                    {active > 0 ? 'Total de lo filtrado' : 'Total registrado'}
                </p>
                <p className="mt-1 text-3xl font-bold">{formatMoney(totals.sum)}</p>
                <p className="mt-0.5 text-xs opacity-80">
                    en {totals.count} {totals.count === 1 ? 'gasto' : 'gastos'}
                    {totals.count > 0 && ` · promedio ${formatMoneyShort(totals.sum / totals.count)}`}
                </p>
            </Card>

            <div className="mt-3">
                <Input
                    value={form.q}
                    onValueChange={(v) => set({ q: v }, false)}
                    placeholder="Buscar por descripción…"
                    startContent={<Search size={16} className="text-default-400" />}
                    isClearable
                    onClear={() => set({ q: '' })}
                    radius="full"
                />
            </div>

            {/* Atajos de fecha */}
            <div className="mt-3 flex gap-2 overflow-x-auto hide-scrollbar">
                {RANGES.map((range) => {
                    const on = activeRange?.key === range.key;
                    return (
                        <button
                            key={range.key}
                            onClick={() => set(on
                                ? { from: '', to: '' }
                                : { from: range.from().format('YYYY-MM-DD'), to: range.to().format('YYYY-MM-DD') })}
                            className={`shrink-0 rounded-full px-3.5 py-1.5 text-xs font-medium transition active:scale-95 ${
                                on ? 'bg-primary text-primary-foreground' : 'bg-content2 text-default-500'
                            }`}
                        >
                            {range.label}
                        </button>
                    );
                })}
                <button
                    onClick={() => set({ receipts: form.receipts === '1' ? '' : '1' })}
                    className={`shrink-0 rounded-full px-3.5 py-1.5 text-xs font-medium transition active:scale-95 ${
                        form.receipts === '1' ? 'bg-primary text-primary-foreground' : 'bg-content2 text-default-500'
                    }`}
                >
                    📎 Con comprobante
                </button>
            </div>

            {showFilters && (
                <Card className="mt-3 flex flex-col gap-3">
                    <Select
                        label="Categoría" size="sm"
                        selectedKeys={form.category ? [form.category] : []}
                        onSelectionChange={(keys) => set({ category: String(Array.from(keys)[0] ?? '') })}
                    >
                        {categories.map((c) => <SelectItem key={String(c.id)}>{c.name}</SelectItem>)}
                    </Select>
                    <Select
                        label="Registrado por" size="sm"
                        selectedKeys={form.member ? [form.member] : []}
                        onSelectionChange={(keys) => set({ member: String(Array.from(keys)[0] ?? '') })}
                    >
                        {members.map((m) => <SelectItem key={String(m.id)}>{`${m.avatar_emoji} ${m.name}`}</SelectItem>)}
                    </Select>
                    <div className="flex gap-2">
                        <Input type="date" size="sm" label="Desde" value={form.from} onValueChange={(v) => set({ from: v })} />
                        <Input type="date" size="sm" label="Hasta" value={form.to} onValueChange={(v) => set({ to: v })} />
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-sm text-default-500">Solo con comprobante</span>
                        <Switch
                            size="sm" color="primary"
                            isSelected={form.receipts === '1'}
                            onValueChange={(on) => set({ receipts: on ? '1' : '' })}
                        />
                    </div>
                    {active > 0 && (
                        <Button
                            variant="flat" radius="full" size="sm" startContent={<X size={14} />}
                            onPress={clear}
                        >
                            Quitar filtros
                        </Button>
                    )}
                </Card>
            )}

            <SectionHeader
                title={`Página ${expenses.current_page} de ${expenses.last_page || 1}`}
            />

            {expenses.data.length === 0 ? (
                <EmptyState
                    emoji="🔍"
                    title="Ningún gasto coincide"
                    hint="Prueba con otro texto, otra fecha o quita los filtros."
                />
            ) : (
                <div className="flex flex-col gap-3">
                    {groupByMonth(expenses.data).map(([month, rows]) => (
                        <div key={month}>
                            <p className="mb-1.5 px-1 text-[11px] font-semibold uppercase tracking-wide text-default-400">
                                {month} · {formatMoneyShort(rows.reduce((sum, e) => sum + parseFloat(e.amount), 0))}
                            </p>
                            {/* La lista entra de una pieza, sin escalonar fila
                                a fila: con 30 movimientos el escalonado se hace
                                largo, y una fila que empieza invisible depende
                                de que la animación termine para poder leerse. */}
                            <Card className="animate-pop-in divide-y divide-divider !p-0">
                                {rows.map((expense) => (
                                    <ExpenseRow
                                        key={expense.id}
                                        expense={expense}
                                        onEdit={(e) => { setEditing(e); editModal.onOpen(); }}
                                    />
                                ))}
                            </Card>
                        </div>
                    ))}
                </div>
            )}

            {expenses.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between gap-2">
                    <Button
                        variant="flat" radius="full" size="sm"
                        isDisabled={expenses.current_page <= 1}
                        onPress={() => apply(form, expenses.current_page - 1)}
                    >
                        ‹ Anteriores
                    </Button>
                    <span className="text-xs text-default-400">
                        {expenses.current_page} / {expenses.last_page}
                    </span>
                    <Button
                        variant="flat" radius="full" size="sm"
                        isDisabled={expenses.current_page >= expenses.last_page}
                        onPress={() => apply(form, expenses.current_page + 1)}
                    >
                        Siguientes ›
                    </Button>
                </div>
            )}

            <ExpenseModal
                isOpen={editModal.isOpen}
                onClose={editModal.onClose}
                categories={categories}
                expense={editing}
            />
        </AppLayout>
    );
}

/** Agrupa por mes conservando el orden que ya trae el servidor. */
function groupByMonth(expenses: Expense[]): [string, Expense[]][] {
    const groups = new Map<string, Expense[]>();

    expenses.forEach((expense) => {
        const label = dayjs(expense.date).format('MMMM YYYY');
        const key = label.charAt(0).toUpperCase() + label.slice(1);
        groups.set(key, [...(groups.get(key) ?? []), expense]);
    });

    return Array.from(groups.entries());
}

/** Tocar la fila abre la edición; ahí es donde se le adjunta la factura. */
function ExpenseRow({ expense, onEdit }: { expense: Expense; onEdit: (expense: Expense) => void }) {
    const del = () => {
        if (confirm(`¿Eliminar "${expense.description}"?`)) {
            router.delete(`/finanzas/gastos/${expense.id}`, { preserveScroll: true });
        }
    };

    return (
        <div className="flex items-center gap-3 px-4 py-3">
            {expense.receipt_url ? (
                <ReceiptViewer url={expense.receipt_url} alt={expense.description} size={38} />
            ) : (
                <div className="flex h-[38px] w-[38px] items-center justify-center rounded-full bg-content2 text-sm">
                    {expense.category?.name?.[0] ?? '·'}
                </div>
            )}
            <button onClick={() => onEdit(expense)} className="min-w-0 flex-1 text-left active:opacity-60">
                <p className="truncate text-sm font-medium">{expense.description}</p>
                <p className="truncate text-xs text-default-400">
                    {expense.category?.name ?? 'Sin categoría'} · {formatDate(expense.date, 'D MMM YYYY')}
                    {!expense.receipt_url && ' · sin comprobante'}
                </p>
            </button>
            <MemberBadge member={expense.creator} size={22} />
            <button onClick={() => onEdit(expense)} className="shrink-0 font-semibold active:opacity-60">
                {formatMoney(expense.amount)}
            </button>
            <button onClick={del} aria-label="Eliminar gasto" className="shrink-0 text-default-300 active:text-rose-500">
                <Trash2 size={16} />
            </button>
        </div>
    );
}
