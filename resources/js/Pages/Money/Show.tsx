import { FormEvent, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    Button, Input, Modal, ModalBody, ModalContent, ModalFooter, ModalHeader, useDisclosure,
} from '@heroui/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Ring from '@/Components/ui/Ring';
import AmountModal from '@/Components/ui/AmountModal';
import DecimalInput from '@/Components/ui/DecimalInput';
import ReceiptViewer from '@/Components/ui/ReceiptViewer';
import { Card, EmptyState, MemberBadge, SectionHeader, StatTile } from '@/Components/ui/primitives';
import { formatDate, formatMoney, formatMoneyShort } from '@/lib/format';
import { accent } from '@/lib/accent';
import { MoneyAccount, MoneyEntry, MoneyTotals, Paginated } from '@/types';

interface Props {
    account: MoneyAccount;
    entries: Paginated<MoneyEntry>;
    totals: MoneyTotals;
}

/** Lo que cambia entre una deuda y una meta: solo las palabras. */
const COPY = {
    debt: {
        section: 'Deudas',
        entry: 'abono',
        entries: 'Abonos',
        addCta: 'Registrar abono',
        addTitle: (name: string) => `Abonar a ${name}`,
        amountLabel: 'Monto del abono',
        movedLabel: 'Pagado',
        remainingLabel: 'Falta por pagar',
        empty: 'Sin abonos todavía',
        emptyHint: 'Registra el primer pago y adjunta el recibo del banco.',
        back: '/dinero',
    },
    goal: {
        section: 'Metas',
        entry: 'aporte',
        entries: 'Aportes',
        addCta: 'Añadir aporte 💪',
        addTitle: (name: string) => `Aportar a ${name}`,
        amountLabel: 'Monto del aporte',
        movedLabel: 'Ahorrado',
        remainingLabel: 'Falta para la meta',
        empty: 'Sin aportes todavía',
        emptyHint: 'Registra el primer aporte y adjunta el comprobante.',
        back: '/dinero',
    },
} as const;

export default function MoneyShow({ account, entries, totals }: Props) {
    const copy = COPY[account.kind];
    const a = accent(account.color);
    const addModal = useDisclosure();
    const editModal = useDisclosure();

    const base = account.kind === 'debt'
        ? `/dinero/deudas/${account.id}`
        : `/dinero/metas/${account.id}`;
    const entryUrl = account.kind === 'debt' ? `${base}/abono` : `${base}/aporte`;

    const removeEntry = (id: number) => {
        if (confirm(`¿Eliminar este ${copy.entry}?`)) {
            router.delete(`${entryUrl}/${id}`, { preserveScroll: true });
        }
    };

    const removeAccount = () => {
        if (confirm(`¿Eliminar "${account.name}" y todo su historial?`)) {
            router.delete(base);
        }
    };

    return (
        <AppLayout
            title={account.name}
            subtitle={copy.section}
            back={copy.back}
            right={
                <Button isIconOnly size="sm" variant="light" radius="full" aria-label="Editar" onPress={editModal.onOpen}>
                    <Pencil size={16} className="text-default-400" />
                </Button>
            }
        >
            <Head title={account.name} />

            {/* Resumen */}
            <Card className="flex items-center gap-4">
                <Ring value={account.progress} size={92} stroke={10} color={a.ring}>
                    <span className="text-base font-bold">{Math.round(account.progress)}%</span>
                </Ring>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <span className="text-2xl">{account.emoji}</span>
                        <h2 className="truncate text-lg font-bold">{account.name}</h2>
                    </div>
                    <p className="mt-1 text-sm text-default-500">
                        {copy.movedLabel}{' '}
                        <span className="font-semibold text-foreground">{formatMoney(account.moved)}</span>{' '}
                        de {formatMoney(account.target)}
                    </p>
                    <p className="text-xs text-default-400">
                        {copy.remainingLabel}: {formatMoney(account.remaining)}
                    </p>
                </div>
            </Card>

            <AccountMeta account={account} />

            <Button
                fullWidth
                color={account.kind === 'debt' ? 'primary' : 'success'}
                variant="flat"
                radius="full"
                className={`mt-3 ${account.kind === 'goal' ? 'text-emerald-700 dark:text-emerald-300' : ''}`}
                startContent={<Plus size={18} />}
                onPress={addModal.onOpen}
            >
                {copy.addCta}
            </Button>

            {/* Números del historial */}
            <SectionHeader title="Resumen" />
            <div className="grid grid-cols-2 gap-3">
                <StatTile label={`${copy.entries} registrados`} value={totals.count} tone={account.color} />
                <StatTile label="Este mes" value={formatMoneyShort(totals.this_month)} tone={account.color} />
                <StatTile label={`${copy.entry === 'abono' ? 'Abono' : 'Aporte'} promedio`} value={formatMoneyShort(totals.average)} tone="slate" />
                <StatTile
                    label="Desde"
                    value={totals.first_date ? formatDate(totals.first_date, 'D MMM YY') : '—'}
                    tone="slate"
                />
            </div>

            {/* Historial completo */}
            <SectionHeader
                title={`${copy.entries} (${totals.count})`}
                action={
                    entries.last_page > 1 && (
                        <span className="text-xs text-default-400">
                            Página {entries.current_page} de {entries.last_page}
                        </span>
                    )
                }
            />

            {/* La lista entra de una pieza, sin escalonar fila a fila: el
                historial puede traer 25 movimientos y ninguno debe depender de
                que termine una animación para poder leerse. */}
            {entries.data.length === 0 ? (
                <EmptyState emoji="🧾" title={copy.empty} hint={copy.emptyHint} />
            ) : (
                <Card className="animate-pop-in divide-y divide-divider !p-0">
                    {entries.data.map((entry) => (
                        <div key={entry.id} className="flex items-center gap-3 px-4 py-3">
                            {entry.receipt_url ? (
                                <ReceiptViewer url={entry.receipt_url} alt={`${formatMoney(entry.amount)} · ${formatDate(entry.date)}`} />
                            ) : (
                                <MemberBadge member={entry.member} size={40} />
                            )}
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                                    +{formatMoney(entry.amount)}
                                </p>
                                <p className="truncate text-xs text-default-400">
                                    {formatDate(entry.date, 'D MMM YYYY')}
                                    {entry.member && ` · ${entry.member.name}`}
                                    {entry.note && ` · ${entry.note}`}
                                </p>
                            </div>
                            {entry.receipt_url && <MemberBadge member={entry.member} size={22} />}
                            <button
                                aria-label={`Eliminar ${copy.entry}`}
                                onClick={() => removeEntry(entry.id)}
                                className="shrink-0 text-default-300 active:text-rose-500"
                            >
                                <Trash2 size={16} />
                            </button>
                        </div>
                    ))}
                </Card>
            )}

            <Pager page={entries} base={base} />

            <SectionHeader title="Zona de riesgo" />
            <Card className="!py-3">
                <Button
                    fullWidth variant="light" color="danger" radius="full"
                    startContent={<Trash2 size={16} />} onPress={removeAccount}
                >
                    Eliminar {account.kind === 'debt' ? 'esta deuda' : 'esta meta'}
                </Button>
            </Card>

            <AmountModal
                isOpen={addModal.isOpen}
                onClose={addModal.onClose}
                title={copy.addTitle(account.name)}
                action={entryUrl}
                ctaLabel={account.kind === 'debt' ? 'Abonar' : 'Aportar'}
                amountLabel={copy.amountLabel}
            />

            <EditModal disclosure={editModal} account={account} url={base} />
        </AppLayout>
    );
}

/** Los datos propios de cada tipo: prestamista y cuota, o fecha objetivo. */
function AccountMeta({ account }: { account: MoneyAccount }) {
    const rows: { label: string; value: string }[] = [];

    if (account.kind === 'debt') {
        if (account.lender) rows.push({ label: 'A quién', value: account.lender });
        if (account.monthly_payment) rows.push({ label: 'Cuota mensual', value: formatMoney(account.monthly_payment) });
        if (account.due_day) rows.push({ label: 'Se paga el día', value: String(account.due_day) });
    } else if (account.target_date) {
        rows.push({ label: 'Fecha objetivo', value: formatDate(account.target_date, 'D MMM YYYY') });
    }

    if (rows.length === 0) return null;

    return (
        <Card className="mt-3 divide-y divide-divider !py-0">
            {rows.map((row) => (
                <div key={row.label} className="flex items-center justify-between py-2.5 text-sm">
                    <span className="text-default-500">{row.label}</span>
                    <span className="font-medium">{row.value}</span>
                </div>
            ))}
        </Card>
    );
}

function Pager({ page, base }: { page: Paginated<MoneyEntry>; base: string }) {
    if (page.last_page <= 1) return null;

    return (
        <div className="mt-3 flex items-center justify-between gap-2">
            <Button
                variant="flat" radius="full" size="sm"
                isDisabled={page.current_page <= 1}
                onPress={() => router.get(base, { page: page.current_page - 1 }, { preserveScroll: true })}
            >
                ‹ Anteriores
            </Button>
            <span className="text-xs text-default-400">{page.current_page} / {page.last_page}</span>
            <Button
                variant="flat" radius="full" size="sm"
                isDisabled={page.current_page >= page.last_page}
                onPress={() => router.get(base, { page: page.current_page + 1 }, { preserveScroll: true })}
            >
                Siguientes ›
            </Button>
        </div>
    );
}

function EditModal({
    disclosure, account, url,
}: {
    disclosure: ReturnType<typeof useDisclosure>;
    account: MoneyAccount;
    url: string;
}) {
    const [form, setForm] = useState({
        name: account.name,
        emoji: account.emoji,
        amount: String(account.target),
        lender: account.lender ?? '',
        monthly_payment: account.monthly_payment ? String(account.monthly_payment) : '',
        target_date: account.target_date ?? '',
    });
    const [processing, setProcessing] = useState(false);
    const isDebt = account.kind === 'debt';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        const payload = isDebt
            ? {
                name: form.name,
                emoji: form.emoji,
                color: account.color,
                total_amount: form.amount,
                lender: form.lender || null,
                monthly_payment: form.monthly_payment || null,
            }
            : {
                name: form.name,
                emoji: form.emoji,
                color: account.color,
                target_amount: form.amount,
                target_date: form.target_date || null,
            };

        router.patch(url, payload, {
            preserveScroll: true,
            onSuccess: () => disclosure.onClose(),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Modal isOpen={disclosure.isOpen} onClose={disclosure.onClose} placement="center" backdrop="blur" size="sm">
            <ModalContent>
                <form onSubmit={submit}>
                    <ModalHeader>Editar {isDebt ? 'deuda' : 'meta'}</ModalHeader>
                    <ModalBody className="gap-3">
                        <div className="flex gap-2">
                            <Input className="w-16" label="Emoji" value={form.emoji} onValueChange={(v) => setForm({ ...form, emoji: v })} />
                            <Input className="flex-1" label="Nombre" value={form.name} onValueChange={(v) => setForm({ ...form, name: v })} isRequired />
                        </div>
                        <DecimalInput
                            label={isDebt ? 'Monto total' : 'Meta'} startContent="$"
                            value={form.amount} onValueChange={(v) => setForm({ ...form, amount: v })} isRequired
                        />
                        {isDebt ? (
                            <>
                                <Input label="A quién le debes (opcional)" value={form.lender} onValueChange={(v) => setForm({ ...form, lender: v })} />
                                <DecimalInput
                                    label="Cuota mensual (opcional)" startContent="$"
                                    value={form.monthly_payment} onValueChange={(v) => setForm({ ...form, monthly_payment: v })}
                                />
                            </>
                        ) : (
                            <Input
                                type="date" label="Fecha objetivo (opcional)"
                                value={form.target_date} onValueChange={(v) => setForm({ ...form, target_date: v })}
                            />
                        )}
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={disclosure.onClose}>Cancelar</Button>
                        <Button color="primary" type="submit" isLoading={processing} isDisabled={!form.name.trim()}>
                            Guardar
                        </Button>
                    </ModalFooter>
                </form>
            </ModalContent>
        </Modal>
    );
}
