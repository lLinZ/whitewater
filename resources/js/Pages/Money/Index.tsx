import { FormEvent, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    Tabs, Tab, Button, useDisclosure, Modal, ModalContent, ModalHeader, ModalBody, ModalFooter, Input,
} from '@heroui/react';
import { ChevronRight, Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Ring from '@/Components/ui/Ring';
import { Card, EmptyState, MemberBadge } from '@/Components/ui/primitives';
import AmountModal from '@/Components/ui/AmountModal';
import DecimalInput from '@/Components/ui/DecimalInput';
import ReceiptViewer from '@/Components/ui/ReceiptViewer';
import { formatMoney, formatMoneyShort, formatDate } from '@/lib/format';
import { accent } from '@/lib/accent';
import { Debt, SavingsGoal } from '@/types';

interface Props {
    debts: Debt[];
    goals: SavingsGoal[];
    summary: { totalDebt: number; totalPaid: number; totalSaved: number; savingsTarget: number };
}

export default function MoneyIndex({ debts, goals, summary }: Props) {
    const [tab, setTab] = useState<'debts' | 'savings'>('debts');
    const [payTarget, setPayTarget] = useState<{ url: string; title: string; cta: string; label: string } | null>(null);
    const newDebt = useDisclosure();
    const newGoal = useDisclosure();

    return (
        <AppLayout title="Dinero" subtitle="Deudas y metas de ahorro">
            <Head title="Dinero" />

            {/* Resumen */}
            <div className="grid grid-cols-2 gap-3">
                <Card className="bg-gradient-to-br from-rose-500 to-red-600 text-white">
                    <p className="text-xs opacity-90">Deuda total</p>
                    <p className="mt-1 text-2xl font-bold">{formatMoneyShort(summary.totalDebt)}</p>
                    <p className="mt-0.5 text-[11px] opacity-80">{formatMoneyShort(summary.totalPaid)} ya pagados</p>
                </Card>
                <Card className="bg-gradient-to-br from-emerald-500 to-green-600 text-white">
                    <p className="text-xs opacity-90">Ahorrado</p>
                    <p className="mt-1 text-2xl font-bold">{formatMoneyShort(summary.totalSaved)}</p>
                    <p className="mt-0.5 text-[11px] opacity-80">de {formatMoneyShort(summary.savingsTarget)}</p>
                </Card>
            </div>

            <div className="mt-4">
                <Tabs
                    fullWidth
                    selectedKey={tab}
                    onSelectionChange={(k) => setTab(k as 'debts' | 'savings')}
                    color="primary"
                    radius="full"
                    classNames={{ tabList: 'bg-content2' }}
                >
                    <Tab key="debts" title="Deudas" />
                    <Tab key="savings" title="Ahorros" />
                </Tabs>
            </div>

            {/* Deudas */}
            {tab === 'debts' && (
                <div className="mt-4 flex flex-col gap-3">
                    {debts.length === 0 && (
                        <EmptyState emoji="🎉" title="Sin deudas registradas" hint="Agrega una deuda para llevar su progreso de pago." />
                    )}
                    {debts.map((d, i) => (
                        <AccountCard
                            key={d.id}
                            index={i}
                            href={`/dinero/deudas/${d.id}`}
                            emoji={d.emoji}
                            name={d.name}
                            color={d.color}
                            progress={d.progress}
                            headline={<>Faltan <span className="font-semibold text-foreground">{formatMoney(d.remaining_amount)}</span></>}
                            sub={`de ${formatMoney(d.total_amount)}`}
                            cta="Registrar abono"
                            ctaColor="primary"
                            onCta={() => setPayTarget({
                                url: `/dinero/deudas/${d.id}/abono`,
                                title: `Abonar a ${d.name}`,
                                cta: 'Abonar',
                                label: 'Monto del abono',
                            })}
                            entries={(d.payments ?? []).map((p) => ({
                                id: p.id, amount: p.amount, date: p.date, member: p.payer, receipt_url: p.receipt_url,
                            }))}
                            entryCount={d.payments_count ?? (d.payments?.length ?? 0)}
                            entryNoun={['abono', 'abonos']}
                        />
                    ))}
                    <Button variant="flat" startContent={<Plus size={18} />} radius="full" className="mt-1" onPress={newDebt.onOpen}>
                        Nueva deuda
                    </Button>
                </div>
            )}

            {/* Ahorros */}
            {tab === 'savings' && (
                <div className="mt-4 flex flex-col gap-3">
                    {goals.length === 0 && (
                        <EmptyState emoji="🎯" title="Sin metas de ahorro" hint="Crea una meta (ej: negocio Crotone) y ve creciendo tu progreso." />
                    )}
                    {goals.map((g, i) => (
                        <AccountCard
                            key={g.id}
                            index={i}
                            href={`/dinero/metas/${g.id}`}
                            emoji={g.emoji}
                            name={g.name}
                            color={g.color}
                            progress={g.progress}
                            headline={<><span className="font-semibold text-foreground">{formatMoney(g.current_amount)}</span> de {formatMoney(g.target_amount)}</>}
                            sub={g.target_date ? `Meta: ${formatDate(g.target_date, 'D MMM YYYY')}` : undefined}
                            cta="Añadir aporte 💪"
                            ctaColor="success"
                            onCta={() => setPayTarget({
                                url: `/dinero/metas/${g.id}/aporte`,
                                title: `Aportar a ${g.name}`,
                                cta: 'Aportar',
                                label: 'Monto del aporte',
                            })}
                            entries={(g.contributions ?? []).map((c) => ({
                                id: c.id, amount: c.amount, date: c.date, member: c.contributor, receipt_url: c.receipt_url,
                            }))}
                            entryCount={g.contributions_count ?? (g.contributions?.length ?? 0)}
                            entryNoun={['aporte', 'aportes']}
                        />
                    ))}
                    <Button variant="flat" startContent={<Plus size={18} />} radius="full" className="mt-1" onPress={newGoal.onOpen}>
                        Nueva meta
                    </Button>
                </div>
            )}

            {/* Modal abono/aporte */}
            {payTarget && (
                <AmountModal
                    isOpen={!!payTarget}
                    onClose={() => setPayTarget(null)}
                    title={payTarget.title}
                    action={payTarget.url}
                    ctaLabel={payTarget.cta}
                    amountLabel={payTarget.label}
                />
            )}

            <NewDebtModal disclosure={newDebt} />
            <NewGoalModal disclosure={newGoal} />
        </AppLayout>
    );
}

interface PreviewEntry {
    id: number;
    amount: string;
    date: string;
    member?: { id: number; name: string; avatar_emoji: string; avatar_url?: string | null; color: string } | null;
    receipt_url?: string | null;
}

/**
 * Tarjeta de deuda o meta. Solo enseña los tres últimos movimientos: el
 * historial entero, con comprobantes, vive en la pantalla de detalle.
 */
function AccountCard({
    index, href, emoji, name, color, progress, headline, sub, cta, ctaColor, onCta, entries, entryCount, entryNoun,
}: {
    index: number;
    href: string;
    emoji: string;
    name: string;
    color: string;
    progress: number;
    headline: React.ReactNode;
    sub?: string;
    cta: string;
    ctaColor: 'primary' | 'success';
    onCta: () => void;
    entries: PreviewEntry[];
    entryCount: number;
    entryNoun: [string, string];
}) {
    const a = accent(color);
    const noun = entryCount === 1 ? entryNoun[0] : entryNoun[1];

    return (
        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: index * 0.05 }}>
            <Card>
                <Link href={href} className="flex items-center gap-4 active:opacity-70">
                    <Ring value={progress} size={80} stroke={9} color={a.ring}>
                        <span className="text-sm font-bold">{Math.round(progress)}%</span>
                    </Ring>
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <span className="text-lg">{emoji}</span>
                            <h3 className="truncate font-semibold">{name}</h3>
                        </div>
                        <p className="mt-0.5 text-sm text-default-500">{headline}</p>
                        {sub && <p className="text-xs text-default-400">{sub}</p>}
                    </div>
                    <ChevronRight size={18} className="shrink-0 text-default-300" />
                </Link>

                <Button
                    fullWidth color={ctaColor} variant="flat" radius="full"
                    className={`mt-3 ${ctaColor === 'success' ? 'text-emerald-700 dark:text-emerald-300' : ''}`}
                    onPress={onCta}
                >
                    {cta}
                </Button>

                {entries.length > 0 && (
                    <div className="mt-3 border-t border-divider pt-2">
                        {entries.map((entry) => (
                            <div key={entry.id} className="flex items-center gap-2 py-1 text-sm">
                                {entry.receipt_url ? (
                                    <ReceiptViewer
                                        url={entry.receipt_url}
                                        alt={`${formatMoney(entry.amount)} · ${formatDate(entry.date)}`}
                                        size={20}
                                    />
                                ) : (
                                    <MemberBadge member={entry.member} size={20} />
                                )}
                                <span className="flex-1 text-default-500">{formatDate(entry.date)}</span>
                                <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                    +{formatMoney(entry.amount)}
                                </span>
                            </div>
                        ))}
                        <Link
                            href={href}
                            className="mt-1 flex items-center justify-center gap-1 py-1.5 text-xs font-medium text-primary active:opacity-60"
                        >
                            Ver los {entryCount} {noun} ›
                        </Link>
                    </div>
                )}
            </Card>
        </motion.div>
    );
}

function NewDebtModal({ disclosure }: { disclosure: ReturnType<typeof useDisclosure> }) {
    const [form, setForm] = useState({ name: '', total_amount: '', monthly_payment: '', emoji: '🚗' });
    const [processing, setProcessing] = useState(false);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.post('/dinero/deudas', { ...form, color: 'rose' }, {
            preserveScroll: true,
            onSuccess: () => { setForm({ name: '', total_amount: '', monthly_payment: '', emoji: '🚗' }); disclosure.onClose(); },
            onFinish: () => setProcessing(false),
        });
    };
    return (
        <Modal isOpen={disclosure.isOpen} onClose={disclosure.onClose} placement="center" backdrop="blur" size="sm">
            <ModalContent>
                <form onSubmit={submit}>
                    <ModalHeader>Nueva deuda</ModalHeader>
                    <ModalBody className="gap-3">
                        <div className="flex gap-2">
                            <Input className="w-16" label="Emoji" value={form.emoji} onValueChange={(v) => setForm({ ...form, emoji: v })} />
                            <Input className="flex-1" label="Nombre" placeholder="Carro" value={form.name} onValueChange={(v) => setForm({ ...form, name: v })} isRequired />
                        </div>
                        <DecimalInput label="Monto total" startContent="$" value={form.total_amount} onValueChange={(v) => setForm({ ...form, total_amount: v })} isRequired />
                        <DecimalInput label="Cuota mensual (opcional)" startContent="$" value={form.monthly_payment} onValueChange={(v) => setForm({ ...form, monthly_payment: v })} />
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={disclosure.onClose}>Cancelar</Button>
                        <Button color="primary" type="submit" isLoading={processing}>Crear</Button>
                    </ModalFooter>
                </form>
            </ModalContent>
        </Modal>
    );
}

function NewGoalModal({ disclosure }: { disclosure: ReturnType<typeof useDisclosure> }) {
    const [form, setForm] = useState({ name: '', target_amount: '', target_date: '', emoji: '🚀' });
    const [processing, setProcessing] = useState(false);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.post('/dinero/metas', { ...form, color: 'emerald' }, {
            preserveScroll: true,
            onSuccess: () => { setForm({ name: '', target_amount: '', target_date: '', emoji: '🚀' }); disclosure.onClose(); },
            onFinish: () => setProcessing(false),
        });
    };
    return (
        <Modal isOpen={disclosure.isOpen} onClose={disclosure.onClose} placement="center" backdrop="blur" size="sm">
            <ModalContent>
                <form onSubmit={submit}>
                    <ModalHeader>Nueva meta de ahorro</ModalHeader>
                    <ModalBody className="gap-3">
                        <div className="flex gap-2">
                            <Input className="w-16" label="Emoji" value={form.emoji} onValueChange={(v) => setForm({ ...form, emoji: v })} />
                            <Input className="flex-1" label="Nombre" placeholder="Negocio Crotone" value={form.name} onValueChange={(v) => setForm({ ...form, name: v })} isRequired />
                        </div>
                        <DecimalInput label="Meta" startContent="$" value={form.target_amount} onValueChange={(v) => setForm({ ...form, target_amount: v })} isRequired />
                        <Input type="date" label="Fecha objetivo (opcional)" value={form.target_date} onValueChange={(v) => setForm({ ...form, target_date: v })} />
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={disclosure.onClose}>Cancelar</Button>
                        <Button color="primary" type="submit" isLoading={processing}>Crear</Button>
                    </ModalFooter>
                </form>
            </ModalContent>
        </Modal>
    );
}
