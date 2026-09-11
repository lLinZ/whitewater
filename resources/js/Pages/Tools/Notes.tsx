import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Button, Input, Modal, ModalBody, ModalContent, ModalHeader } from '@heroui/react';
import { CalendarCheck2, ChevronRight, Search, Trash2, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, EmptyState, SectionHeader } from '@/Components/ui/primitives';
import NoteComposer from '@/Components/notes/NoteComposer';
import NoteCard from '@/Components/notes/NoteCard';
import { dayjs } from '@/lib/format';
import { accent } from '@/lib/accent';
import type { Note, PageProps, Paginated } from '@/types';

interface Filters {
    q: string;
    tag: string;
    estado: '' | 'pendientes' | 'montadas';
}

interface Props {
    filters: Filters;
    notes: Paginated<Note>;
    tags: string[];
    clients: string[];
}

const STATES: { key: Filters['estado']; label: string }[] = [
    { key: '', label: 'Todas' },
    { key: 'pendientes', label: 'Por montar' },
    { key: 'montadas', label: 'En Monday' },
];

/** "Hoy", "Ayer" o "Jueves 10 de septiembre". */
function dayLabel(date: string): string {
    const day = dayjs(date);
    if (day.isSame(dayjs(), 'day')) return 'Hoy';
    if (day.isSame(dayjs().subtract(1, 'day'), 'day')) return 'Ayer';
    const label = day.format('dddd D [de] MMMM');
    return label.charAt(0).toUpperCase() + label.slice(1);
}

function byDay(notes: Note[]): [string, Note[]][] {
    const groups = new Map<string, Note[]>();
    notes.forEach((note) => {
        const key = dayLabel(note.created_at);
        groups.set(key, [...(groups.get(key) ?? []), note]);
    });
    return Array.from(groups.entries());
}

/**
 * El anotador: arriba se escribe, abajo se consulta.
 *
 * La primera herramienta de la sección Herramientas. Notas rápidas sobre
 * clientes para pasarlas a Monday al final del día.
 */
export default function Notes({ filters, notes, tags, clients }: Props) {
    const { auth, notesPending } = usePage<PageProps>().props;
    const [form, setForm] = useState<Filters>(filters);
    const [editing, setEditing] = useState<Note | null>(null);

    const apply = (next: Filters, page?: number) => {
        const query: Record<string, string | number> = {};
        Object.entries(next).forEach(([key, value]) => { if (value) query[key] = value; });
        if (page && page > 1) query.page = page;
        router.get('/herramientas/notas', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    // La búsqueda por texto se lanza sola tras una pausa al escribir.
    useEffect(() => {
        if (form.q === filters.q) return;
        const timer = setTimeout(() => apply(form), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.q]);

    const set = (changes: Partial<Filters>) => {
        const next = { ...form, ...changes };
        setForm(next);
        if (!('q' in changes)) apply(next);
    };

    const toggleTag = (tag: string) => set({ tag: form.tag === tag ? '' : tag });

    // La nota abierta se refresca con la lista (al quitarle o subirle archivos).
    const open = editing ? notes.data.find((n) => n.id === editing.id) ?? editing : null;

    const destroy = (note: Note) => {
        if (confirm(`¿Borrar la nota${note.client ? ` de ${note.client}` : ''}? También se borran sus archivos.`)) {
            router.delete(`/herramientas/notas/${note.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) });
        }
    };

    const filtering = !!(filters.q || filters.tag || filters.estado);

    return (
        <AppLayout title="Anotador" subtitle="Herramientas · notas de clientes">
            <Head title="Anotador" />

            {notesPending > 0 && (
                <Link href="/herramientas/notas/monday" className="block active:scale-[0.99]">
                    <Card className={`flex items-center gap-3 bg-gradient-to-br !py-3 text-white ${accent(auth.user.color).gradient}`}>
                        <CalendarCheck2 size={22} className="shrink-0" />
                        <div className="min-w-0 flex-1">
                            <p className="font-semibold">
                                {notesPending === 1 ? '1 nota por montar' : `${notesPending} notas por montar`} en Monday
                            </p>
                            <p className="text-xs opacity-85">Tócalo para copiarlas una a una</p>
                        </div>
                        <ChevronRight size={20} />
                    </Card>
                </Link>
            )}

            <SectionHeader title="Nueva nota" />
            <Card className="!p-3">
                <NoteComposer clients={clients} tags={tags} />
            </Card>

            {/* Buscar y filtrar */}
            <SectionHeader title={`Notas (${notes.total})`} />
            <Input
                aria-label="Buscar" placeholder="Buscar cliente o texto" radius="full" variant="flat"
                startContent={<Search size={16} className="text-default-400" />}
                endContent={form.q && (
                    <button onClick={() => setForm((f) => ({ ...f, q: '' }))} aria-label="Borrar búsqueda">
                        <X size={16} className="text-default-400" />
                    </button>
                )}
                value={form.q} onValueChange={(q) => setForm((f) => ({ ...f, q }))}
                classNames={{ input: 'text-base' }}
            />
            <div className="mt-2 flex gap-1 rounded-2xl bg-content2 p-1">
                {STATES.map((s) => (
                    <button
                        key={s.key} onClick={() => set({ estado: s.key })} aria-pressed={form.estado === s.key}
                        className={`flex-1 rounded-xl py-1.5 text-xs font-medium transition ${
                            form.estado === s.key ? 'bg-content1 text-primary shadow-soft' : 'text-default-500'
                        }`}
                    >
                        {s.label}
                    </button>
                ))}
            </div>
            {tags.length > 0 && (
                <div className="hide-scrollbar -mx-1 mt-2 flex gap-1.5 overflow-x-auto px-1">
                    {tags.map((tag) => (
                        <button
                            key={tag} onClick={() => toggleTag(tag)} aria-pressed={form.tag === tag}
                            className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${
                                form.tag === tag ? 'bg-primary text-primary-foreground' : 'bg-default-100 text-default-600'
                            }`}
                        >
                            #{tag}
                        </button>
                    ))}
                </div>
            )}

            {/* La lista, por días */}
            <div className="mt-4 flex flex-col gap-5">
                {notes.data.length === 0 && (filtering ? (
                    <EmptyState emoji="🔎" title="Nada con esos filtros" hint="Prueba con otra palabra o quita la etiqueta." />
                ) : (
                    <EmptyState emoji="📝" title="Aún no hay notas" hint="Escribe arriba el cliente y lo que pasó. Con #etiquetas luego las encuentras al instante." />
                ))}
                {byDay(notes.data).map(([day, list]) => (
                    <div key={day}>
                        <p className="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-default-400">{day}</p>
                        <div className="animate-pop-in flex flex-col gap-2">
                            {list.map((note) => (
                                <NoteCard key={note.id} note={note} onOpen={setEditing} onTag={toggleTag} />
                            ))}
                        </div>
                    </div>
                ))}
            </div>

            {notes.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between">
                    <Button
                        size="sm" variant="flat" radius="full" isDisabled={notes.current_page <= 1}
                        onPress={() => apply(form, notes.current_page - 1)}
                    >
                        ‹ Más recientes
                    </Button>
                    <span className="text-xs text-default-400">{notes.current_page} / {notes.last_page}</span>
                    <Button
                        size="sm" variant="flat" radius="full" isDisabled={notes.current_page >= notes.last_page}
                        onPress={() => apply(form, notes.current_page + 1)}
                    >
                        Anteriores ›
                    </Button>
                </div>
            )}

            {/* Editar */}
            <Modal isOpen={!!open} onClose={() => setEditing(null)} scrollBehavior="inside" backdrop="blur" size="lg">
                <ModalContent>
                    {open && (
                        <>
                            <ModalHeader className="flex items-center justify-between gap-2 pr-12">
                                <span>Editar nota</span>
                                <button
                                    onClick={() => destroy(open)}
                                    className="flex items-center gap-1 text-xs font-medium text-rose-500 active:opacity-60"
                                >
                                    <Trash2 size={14} /> Borrar
                                </button>
                            </ModalHeader>
                            <ModalBody className="pb-5">
                                <p className="text-xs text-default-400">
                                    {dayjs(open.created_at).format('D MMM, h:mm a')}
                                    {open.monday_at ? ` · en Monday desde el ${dayjs(open.monday_at).format('D MMM')}` : ' · por montar en Monday'}
                                </p>
                                <NoteComposer
                                    key={open.id} note={open} clients={clients} tags={tags}
                                    onSaved={() => setEditing(null)}
                                />
                            </ModalBody>
                        </>
                    )}
                </ModalContent>
            </Modal>
        </AppLayout>
    );
}
