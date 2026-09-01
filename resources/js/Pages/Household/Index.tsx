import { Dispatch, FormEvent, SetStateAction, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    Button, useDisclosure, Modal, ModalContent, ModalHeader, ModalBody, ModalFooter, Input, Select, SelectItem,
} from '@heroui/react';
import { Plus, Check, Trash2, Pencil } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Ring from '@/Components/ui/Ring';
import NotificationsToggle from '@/Components/ui/NotificationsToggle';
import { Card, EmptyState, MemberBadge } from '@/Components/ui/primitives';
import { dayjs, fromNow } from '@/lib/format';
import { Routine } from '@/types';

interface Props {
    routines: Routine[];
    stats: { doneToday: number; total: number };
}

/** ISO: 1 = lunes … 7 = domingo, igual que en el backend. */
const DAYS = [
    { value: 1, label: 'L', long: 'lunes' }, { value: 2, label: 'M', long: 'martes' },
    { value: 3, label: 'X', long: 'miércoles' }, { value: 4, label: 'J', long: 'jueves' },
    { value: 5, label: 'V', long: 'viernes' }, { value: 6, label: 'S', long: 'sábado' },
    { value: 7, label: 'D', long: 'domingo' },
];

interface Form { title: string; frequency: string; days: number[] }

const EMPTY: Form = { title: '', frequency: 'daily', days: [] };

/** ¿Le toca a esta rutina el día ISO indicado? Misma regla que el backend. */
function dueOnDay(routine: Routine, day: number): boolean {
    if (routine.frequency === 'weekly' && routine.days?.length) {
        return routine.days.includes(day);
    }
    return true;
}

export default function HouseholdIndex({ routines, stats }: Props) {
    const modal = useDisclosure();
    const todayIso = dayjs().isoWeekday();
    const [editing, setEditing] = useState<Routine | null>(null);
    const [form, setForm] = useState<Form>(EMPTY);
    // null = el día de hoy, tal cual lo calculó el servidor.
    const [viewDay, setViewDay] = useState<number | null>(null);
    const pct = stats.total > 0 ? (stats.doneToday / stats.total) * 100 : 0;

    const showingToday = viewDay === null || viewDay === todayIso;
    const listed = showingToday
        ? routines.filter((r) => r.due_today)
        : routines.filter((r) => dueOnDay(r, viewDay));
    const others = showingToday
        ? routines.filter((r) => !r.due_today)
        : [];

    const openNew = () => { setEditing(null); setForm(EMPTY); modal.onOpen(); };
    const openEdit = (r: Routine) => {
        setEditing(r);
        setForm({ title: r.title, frequency: r.frequency, days: r.days ?? [] });
        modal.onOpen();
    };

    const toggleDone = (r: Routine) => {
        const opts = { preserveScroll: true };
        if (r.done_today) router.delete(`/hogar/${r.id}/completar`, opts);
        else router.post(`/hogar/${r.id}/completar`, {}, opts);
    };

    const del = (id: number, title: string) => {
        if (confirm(`¿Eliminar la rutina "${title}"?`)) router.delete(`/hogar/${id}`, { preserveScroll: true });
    };

    return (
        <AppLayout
            title="Hogar"
            subtitle="Rutinas y tareas"
            right={<Button isIconOnly color="primary" radius="full" size="sm" aria-label="Nueva rutina" onPress={openNew}><Plus size={18} /></Button>}
        >
            <Head title="Hogar" />

            <Card className="flex items-center gap-4 bg-gradient-to-br from-amber-400 to-orange-500 text-white">
                <Ring value={pct} size={80} stroke={9} color="#ffffff" trackClass="text-white/30">
                    <span className="text-sm font-bold">{Math.round(pct)}%</span>
                </Ring>
                <div>
                    <p className="text-lg font-bold">{stats.doneToday} de {stats.total} hoy</p>
                    <p className="text-sm opacity-90">
                        {stats.total === 0
                            ? 'Hoy no toca ninguna rutina 🌴'
                            : pct === 100 ? '¡Casa impecable! ✨' : '¡Vamos, un toque a la vez!'}
                    </p>
                </div>
            </Card>

            {routines.length > 0 && (
                <WeekStrip
                    routines={routines}
                    todayIso={todayIso}
                    selected={viewDay ?? todayIso}
                    onSelect={(day) => setViewDay(day === todayIso ? null : day)}
                />
            )}

            <div className="mt-3">
                <NotificationsToggle />
            </div>

            <div className="mt-4 flex flex-col gap-2">
                {routines.length === 0 && (
                    <EmptyState emoji="🧹" title="Sin rutinas" hint="Crea rutinas como “Lavar platos” o “Limpiar la cocina” y elige qué días toca cada una." />
                )}
                {routines.length > 0 && listed.length === 0 && (
                    <Card className="!py-6 text-center text-sm text-default-400">
                        {showingToday
                            ? 'Hoy no toca ninguna rutina 🌴'
                            : `Nada programado para el ${DAYS[(viewDay ?? todayIso) - 1].long} 🌴`}
                    </Card>
                )}
                {listed.map((r, i) => (
                    <RoutineRow
                        key={r.id} routine={r} index={i}
                        interactive={showingToday}
                        onToggle={toggleDone} onEdit={openEdit} onDelete={del}
                    />
                ))}
            </div>

            {others.length > 0 && (
                <>
                    <p className="mb-2 mt-6 px-1 text-[13px] font-semibold uppercase tracking-wide text-default-500">
                        Otros días
                    </p>
                    <div className="flex flex-col gap-2">
                        {others.map((r, i) => (
                            <RoutineRow
                                key={r.id} routine={r} index={i} muted interactive={false}
                                onToggle={toggleDone} onEdit={openEdit} onDelete={del}
                            />
                        ))}
                    </div>
                </>
            )}

            <RoutineModal disclosure={modal} editing={editing} form={form} setForm={setForm} />
        </AppLayout>
    );
}

/**
 * La semana de un vistazo: cuántas rutinas caen cada día.
 *
 * Configurar días no sirve de nada si luego no se ve el reparto; aquí se nota
 * enseguida que el sábado está cargado y el martes vacío.
 */
function WeekStrip({
    routines, todayIso, selected, onSelect,
}: {
    routines: Routine[];
    todayIso: number;
    selected: number;
    onSelect: (day: number) => void;
}) {
    return (
        <div className="mt-3 flex gap-1.5">
            {DAYS.map((day) => {
                const count = routines.filter((r) => dueOnDay(r, day.value)).length;
                const isToday = day.value === todayIso;
                const isSelected = day.value === selected;

                return (
                    <button
                        key={day.value}
                        onClick={() => onSelect(day.value)}
                        aria-label={`${count} rutinas el ${day.long}`}
                        aria-pressed={isSelected}
                        className={`flex flex-1 flex-col items-center gap-0.5 rounded-2xl py-2 transition active:scale-95 ${
                            isSelected ? 'bg-primary text-primary-foreground' : 'bg-content1 shadow-soft'
                        }`}
                    >
                        <span className={`text-[11px] font-semibold ${isSelected ? '' : isToday ? 'text-primary' : 'text-default-400'}`}>
                            {day.label}
                        </span>
                        <span className={`text-sm font-bold ${isSelected ? '' : count === 0 ? 'text-default-300' : ''}`}>
                            {count}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

function RoutineRow({
    routine: r, index, muted = false, interactive = true, onToggle, onEdit, onDelete,
}: {
    routine: Routine;
    index: number;
    muted?: boolean;
    /** Solo el día de hoy se puede marcar: no se completan tareas del futuro. */
    interactive?: boolean;
    onToggle: (r: Routine) => void;
    onEdit: (r: Routine) => void;
    onDelete: (id: number, title: string) => void;
}) {
    return (
        <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: Math.min(index, 10) * 0.04 }}>
            <Card className={`flex items-center gap-3 !py-3 ${muted ? 'opacity-60' : ''}`}>
                <button
                    aria-label={r.done_today ? 'Marcar como pendiente' : 'Marcar como hecha'}
                    onClick={() => onToggle(r)}
                    disabled={!interactive}
                    className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full border-2 transition active:scale-90 disabled:active:scale-100 ${
                        r.done_today ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-default-300 text-transparent'
                    }`}
                >
                    <Check size={22} strokeWidth={3} />
                </button>
                <button onClick={() => onEdit(r)} className="min-w-0 flex-1 text-left">
                    <p className={`font-medium ${r.done_today && interactive ? 'text-default-400 line-through' : ''}`}>{r.title}</p>
                    <p className="flex flex-wrap items-center gap-1.5 text-xs text-default-400">
                        {r.schedule_label}
                        {r.done_this_month > 0 && <>· {r.done_this_month}× este mes</>}
                        {r.last_completed && (
                            <>· <MemberBadge member={r.last_by} size={16} /> {fromNow(r.last_completed)}</>
                        )}
                    </p>
                </button>
                <button aria-label="Editar" onClick={() => onEdit(r)} className="shrink-0 text-default-300 active:text-primary">
                    <Pencil size={15} />
                </button>
                <button aria-label="Eliminar" onClick={() => onDelete(r.id, r.title)} className="shrink-0 text-default-300 active:text-rose-500">
                    <Trash2 size={16} />
                </button>
            </Card>
        </motion.div>
    );
}

function RoutineModal({
    disclosure, editing, form, setForm,
}: {
    disclosure: ReturnType<typeof useDisclosure>;
    editing: Routine | null;
    form: Form;
    setForm: Dispatch<SetStateAction<Form>>;
}) {
    const [processing, setProcessing] = useState(false);

    // Actualización funcional: dos toques seguidos no deben pisarse entre sí.
    const toggleDay = (day: number) => setForm((f) => ({
        ...f,
        days: f.days.includes(day)
            ? f.days.filter((d) => d !== day)
            : [...f.days, day].sort((a, b) => a - b),
    }));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        const payload = { title: form.title, frequency: form.frequency, days: form.days };
        const opts = {
            preserveScroll: true,
            onSuccess: () => disclosure.onClose(),
            onFinish: () => setProcessing(false),
        };
        if (editing) router.patch(`/hogar/${editing.id}`, payload, opts);
        else router.post('/hogar', payload, opts);
    };

    // "Días de la semana" sin días marcados = una vez por semana, cualquier día.
    const weekly = form.frequency === 'weekly';

    return (
        <Modal isOpen={disclosure.isOpen} onClose={disclosure.onClose} placement="center" backdrop="blur" size="sm">
            <ModalContent>
                <form onSubmit={submit}>
                    <ModalHeader>{editing ? 'Editar rutina' : 'Nueva rutina'}</ModalHeader>
                    <ModalBody className="gap-3">
                        <Input
                            autoFocus label="Tarea" placeholder="Limpiar la cocina"
                            value={form.title} onValueChange={(v) => setForm((f) => ({ ...f, title: v }))} isRequired
                        />
                        <Select
                            label="Frecuencia" selectedKeys={[form.frequency]}
                            onSelectionChange={(k) => setForm((f) => ({ ...f, frequency: String(Array.from(k)[0]) }))}
                        >
                            <SelectItem key="daily">Todos los días</SelectItem>
                            <SelectItem key="weekly">Días de la semana</SelectItem>
                            <SelectItem key="monthly">Una vez al mes</SelectItem>
                        </Select>

                        {weekly && (
                            <div>
                                <p className="mb-2 px-1 text-xs text-default-500">¿Qué días toca?</p>
                                <div className="flex justify-between gap-1">
                                    {DAYS.map((d) => {
                                        const on = form.days.includes(d.value);
                                        return (
                                            <button
                                                key={d.value} type="button" onClick={() => toggleDay(d.value)}
                                                aria-label={d.long} aria-pressed={on}
                                                className={`h-11 flex-1 rounded-2xl text-sm font-semibold transition active:scale-90 ${
                                                    on ? 'bg-primary text-primary-foreground' : 'bg-default-100 text-default-500'
                                                }`}
                                            >
                                                {d.label}
                                            </button>
                                        );
                                    })}
                                </div>
                                <p className="mt-2 px-1 text-xs text-default-400">
                                    {form.days.length === 0
                                        ? 'Sin días marcados: una vez por semana, cualquier día.'
                                        : 'Solo aparecerá pendiente esos días.'}
                                </p>
                            </div>
                        )}
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={disclosure.onClose}>Cancelar</Button>
                        <Button color="primary" type="submit" isLoading={processing} isDisabled={!form.title.trim()}>
                            {editing ? 'Guardar' : 'Crear'}
                        </Button>
                    </ModalFooter>
                </form>
            </ModalContent>
        </Modal>
    );
}
