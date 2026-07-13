import { FormEvent, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    Tabs, Tab, Button, useDisclosure, Modal, ModalContent, ModalHeader, ModalBody, ModalFooter, Input, Chip,
} from '@heroui/react';
import { Plus, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Ring from '@/Components/ui/Ring';
import { Card, EmptyState, MemberBadge } from '@/Components/ui/primitives';
import AmountModal from '@/Components/ui/AmountModal';
import { formatMoney, formatMoneyShort, formatDate } from '@/lib/format';
import { accent } from '@/lib/accent';
import { Debt, SavingsGoal } from '@/types';

interface Props {
    debts: Debt[];
    goals: SavingsGoal[];
    summary: { totalDebt: number; totalSaved: number; savingsTarget: number };
}

export default function MoneyIndex({ debts, goals, summary }: Props) {
    const [tab, setTab] = useState<'debts' | 'savings'>('debts');
    const [payTarget, setPayTarget] = useState<{ url: string; title: string; cta: string; label: string } | null>(null);
    const newDebt = useDisclosure();
    const newGoal = useDisclosure();

    const del = (url: string, msg: string) => {
        if (confirm(msg)) router.delete(url, { preserveScroll: true });
    };

    return (
        <AppLayout title="Dinero" subtitle="Deudas y metas de ahorro">
            <Head title="Dinero" />

            {/* Resumen */}
            <div className="grid grid-cols-2 gap-3">
                <Card className="bg-gradient-to-br from-rose-500 to-red-600 text-white">
                    <p className="text-xs opacity-90">Deuda total</p>
                    <p className="mt-1 text-2xl font-bold">{formatMoneyShort(summary.totalDebt)}</p>
                </Card>
                <Card className="bg-gradient-to-br from-emerald-500 to-green-600 text-white">
                    <p className="text-xs opacity-90">Ahorrado</p>
                    <p className="mt-1 text-2xl font-bold">{formatMoneyShort(summary.totalSaved)}</p>
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
                    {debts.map((d, i) => {
                        const a = accent(d.color);
                        return (
                            <motion.div key={d.id} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.05 }}>
                                <Card>
                                    <div className="flex items-center gap-4">
                                        <Ring value={d.progress} size={80} stroke={9} color={a.ring}>
                                            <span className="text-sm font-bold">{Math.round(d.progress)}%</span>
                                        </Ring>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-lg">{d.emoji}</span>
                                                <h3 className="truncate font-semibold">{d.name}</h3>
                                            </div>
                                            <p className="mt-0.5 text-sm text-default-500">
                                                Faltan <span className="font-semibold text-foreground">{formatMoney(d.remaining_amount)}</span>
                                            </p>
                                            <p className="text-xs text-default-400">de {formatMoney(d.total_amount)}</p>
                                        </div>
                                    </div>
                                    <div className="mt-3 flex items-center gap-2">
                                        <Button
                                            fullWidth color="primary" variant="flat" radius="full"
                                            onPress={() => setPayTarget({ url: `/dinero/deudas/${d.id}/abono`, title: `Abonar a ${d.name}`, cta: 'Abonar', label: 'Monto del abono' })}
                                        >
                                            Registrar abono
                                        </Button>
                                        <Button isIconOnly variant="light" radius="full" onPress={() => del(`/dinero/deudas/${d.id}`, `¿Eliminar la deuda "${d.name}"?`)}>
                                            <Trash2 size={18} className="text-default-400" />
                                        </Button>
                                    </div>
                                    {d.payments && d.payments.length > 0 && (
                                        <div className="mt-3 border-t border-divider pt-2">
                                            {d.payments.slice(0, 3).map((p) => (
                                                <div key={p.id} className="flex items-center justify-between py-1 text-sm">
                                                    <span className="flex items-center gap-2 text-default-500">
                                                        <MemberBadge member={p.payer} size={20} /> {formatDate(p.date)}
                                                    </span>
                                                    <span className="font-medium text-emerald-600">+{formatMoney(p.amount)}</span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </Card>
                            </motion.div>
                        );
                    })}
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
                    {goals.map((g, i) => {
                        const a = accent(g.color);
                        return (
                            <motion.div key={g.id} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.05 }}>
                                <Card>
                                    <div className="flex items-center gap-4">
                                        <Ring value={g.progress} size={80} stroke={9} color={a.ring}>
                                            <span className="text-sm font-bold">{Math.round(g.progress)}%</span>
                                        </Ring>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-lg">{g.emoji}</span>
                                                <h3 className="truncate font-semibold">{g.name}</h3>
                                            </div>
                                            <p className="mt-0.5 text-sm text-default-500">
                                                <span className="font-semibold text-foreground">{formatMoney(g.current_amount)}</span> de {formatMoney(g.target_amount)}
                                            </p>
                                            {g.target_date && <p className="text-xs text-default-400">Meta: {formatDate(g.target_date, 'D MMM YYYY')}</p>}
                                        </div>
                                    </div>
                                    <div className="mt-3 flex items-center gap-2">
                                        <Button
                                            fullWidth color="success" variant="flat" radius="full"
                                            className="text-emerald-700"
                                            onPress={() => setPayTarget({ url: `/dinero/metas/${g.id}/aporte`, title: `Aportar a ${g.name}`, cta: 'Aportar', label: 'Monto del aporte' })}
                                        >
                                            Añadir aporte 💪
                                        </Button>
                                        <Button isIconOnly variant="light" radius="full" onPress={() => del(`/dinero/metas/${g.id}`, `¿Eliminar la meta "${g.name}"?`)}>
                                            <Trash2 size={18} className="text-default-400" />
                                        </Button>
                                    </div>
                                    {g.contributions && g.contributions.length > 0 && (
                                        <div className="mt-3 border-t border-divider pt-2">
                                            {g.contributions.slice(0, 3).map((c) => (
                                                <div key={c.id} className="flex items-center justify-between py-1 text-sm">
                                                    <span className="flex items-center gap-2 text-default-500">
                                                        <MemberBadge member={c.contributor} size={20} /> {formatDate(c.date)}
                                                    </span>
                                                    <span className="font-medium text-emerald-600">+{formatMoney(c.amount)}</span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </Card>
                            </motion.div>
                        );
                    })}
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
                        <Input type="number" step="0.01" label="Monto total" startContent="$" value={form.total_amount} onValueChange={(v) => setForm({ ...form, total_amount: v })} isRequired />
                        <Input type="number" step="0.01" label="Cuota mensual (opcional)" startContent="$" value={form.monthly_payment} onValueChange={(v) => setForm({ ...form, monthly_payment: v })} />
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
                        <Input type="number" step="0.01" label="Meta" startContent="$" value={form.target_amount} onValueChange={(v) => setForm({ ...form, target_amount: v })} isRequired />
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
