import { FormEvent, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    Button, useDisclosure, Modal, ModalContent, ModalHeader, ModalBody, ModalFooter, Input, Select, SelectItem,
} from '@heroui/react';
import { Plus, Check, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Ring from '@/Components/ui/Ring';
import NotificationsToggle from '@/Components/ui/NotificationsToggle';
import { Card, EmptyState, MemberBadge } from '@/Components/ui/primitives';
import { fromNow } from '@/lib/format';
import { accent } from '@/lib/accent';
import { Routine } from '@/types';

interface Props {
    routines: Routine[];
    stats: { doneToday: number; total: number };
}

const FREQ: Record<string, string> = { daily: 'Diaria', weekly: 'Semanal', monthly: 'Mensual' };

export default function HouseholdIndex({ routines, stats }: Props) {
    const newRoutine = useDisclosure();
    const pct = stats.total > 0 ? (stats.doneToday / stats.total) * 100 : 0;

    const complete = (id: number) => router.post(`/hogar/${id}/completar`, {}, { preserveScroll: true });
    const del = (id: number, title: string) => {
        if (confirm(`¿Eliminar la rutina "${title}"?`)) router.delete(`/hogar/${id}`, { preserveScroll: true });
    };

    return (
        <AppLayout
            title="Hogar"
            subtitle="Rutinas y tareas"
            right={<Button isIconOnly color="primary" radius="full" size="sm" onPress={newRoutine.onOpen}><Plus size={18} /></Button>}
        >
            <Head title="Hogar" />

            <Card className="flex items-center gap-4 bg-gradient-to-br from-amber-400 to-orange-500 text-white">
                <Ring value={pct} size={80} stroke={9} color="#ffffff" trackClass="text-white/30">
                    <span className="text-sm font-bold">{Math.round(pct)}%</span>
                </Ring>
                <div>
                    <p className="text-lg font-bold">{stats.doneToday} de {stats.total} hoy</p>
                    <p className="text-sm opacity-90">
                        {pct === 100 && stats.total > 0 ? '¡Casa impecable! ✨' : '¡Vamos, un toque a la vez!'}
                    </p>
                </div>
            </Card>

            <div className="mt-3">
                <NotificationsToggle />
            </div>

            <div className="mt-4 flex flex-col gap-2">
                {routines.length === 0 && (
                    <EmptyState emoji="🧹" title="Sin rutinas" hint="Crea rutinas como 'Lavar platos' o 'Sacar basura'." />
                )}
                {routines.map((r, i) => {
                    const a = accent(r.last_by?.color);
                    return (
                        <motion.div key={r.id} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.04 }}>
                            <Card className="flex items-center gap-3 !py-3">
                                <button
                                    onClick={() => complete(r.id)}
                                    className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full border-2 transition active:scale-90 ${
                                        r.done_today ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-default-300 text-transparent'
                                    }`}
                                >
                                    <Check size={22} strokeWidth={3} />
                                </button>
                                <div className="min-w-0 flex-1">
                                    <p className={`font-medium ${r.done_today ? 'text-default-400 line-through' : ''}`}>{r.title}</p>
                                    <p className="flex items-center gap-1.5 text-xs text-default-400">
                                        {FREQ[r.frequency]}
                                        {r.last_completed && (
                                            <>· <MemberBadge member={r.last_by} size={16} /> {fromNow(r.last_completed)}</>
                                        )}
                                    </p>
                                </div>
                                <button onClick={() => del(r.id, r.title)} className="text-default-300 active:text-rose-500">
                                    <Trash2 size={16} />
                                </button>
                            </Card>
                        </motion.div>
                    );
                })}
            </div>

            <NewRoutineModal disclosure={newRoutine} />
        </AppLayout>
    );
}

function NewRoutineModal({ disclosure }: { disclosure: ReturnType<typeof useDisclosure> }) {
    const [form, setForm] = useState({ title: '', frequency: 'daily' });
    const [processing, setProcessing] = useState(false);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.post('/hogar', form, {
            preserveScroll: true,
            onSuccess: () => { setForm({ title: '', frequency: 'daily' }); disclosure.onClose(); },
            onFinish: () => setProcessing(false),
        });
    };
    return (
        <Modal isOpen={disclosure.isOpen} onClose={disclosure.onClose} placement="center" backdrop="blur" size="sm">
            <ModalContent>
                <form onSubmit={submit}>
                    <ModalHeader>Nueva rutina</ModalHeader>
                    <ModalBody className="gap-3">
                        <Input autoFocus label="Tarea" placeholder="Lavar los platos" value={form.title} onValueChange={(v) => setForm({ ...form, title: v })} isRequired />
                        <Select label="Frecuencia" selectedKeys={[form.frequency]} onSelectionChange={(k) => setForm({ ...form, frequency: String(Array.from(k)[0]) })}>
                            <SelectItem key="daily">Diaria</SelectItem>
                            <SelectItem key="weekly">Semanal</SelectItem>
                            <SelectItem key="monthly">Mensual</SelectItem>
                        </Select>
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
