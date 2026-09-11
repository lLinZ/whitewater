import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Button, Switch } from '@heroui/react';
import { Check, Copy, CopyCheck } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, EmptyState, SectionHeader } from '@/Components/ui/primitives';
import NotificationsToggle from '@/Components/ui/NotificationsToggle';
import NoteAttachments from '@/Components/notes/NoteAttachments';
import { NoteBody } from '@/Components/notes/NoteCard';
import { dayjs } from '@/lib/format';
import { copyText, mondayText } from '@/lib/notes';
import type { Note } from '@/types';

interface Props {
    notes: Note[];
    reminderAt: string | null;
}

/**
 * El final del día: lo que falta por pasar a Monday.
 *
 * Cada nota tiene su "Copiar", que deja el texto listo para pegar con el
 * formato de siempre, y su "Ya la monté". Los archivos se pueden descargar
 * para subirlos también.
 */
export default function Monday({ notes, reminderAt }: Props) {
    const [copied, setCopied] = useState<number | 'all' | null>(null);
    const [time, setTime] = useState(reminderAt ?? '18:00');

    const copy = async (key: number | 'all', text: string) => {
        if (await copyText(text)) {
            setCopied(key);
            setTimeout(() => setCopied((current) => (current === key ? null : current)), 2000);
        }
    };

    const markDone = (note: Note) => router.patch(
        `/herramientas/notas/${note.id}/monday`,
        { done: true },
        { preserveScroll: true },
    );

    const markAll = () => {
        if (confirm(`¿Marcar ${notes.length === 1 ? 'la nota' : `las ${notes.length} notas`} como montadas en Monday?`)) {
            router.post('/herramientas/notas/monday', {}, { preserveScroll: true });
        }
    };

    const saveReminder = (next: string | null) => router.patch(
        '/herramientas/notas/recordatorio',
        { time: next },
        { preserveScroll: true, preserveState: true },
    );

    return (
        <AppLayout title="Para Monday" subtitle="Lo que falta por montar" back="/herramientas/notas">
            <Head title="Para Monday" />

            {notes.length === 0 ? (
                <EmptyState emoji="🎉" title="Todo al día" hint="No queda nada por montar en Monday." />
            ) : (
                <>
                    <Card className="flex flex-col gap-3">
                        <p className="text-sm">
                            <b>{notes.length === 1 ? '1 nota' : `${notes.length} notas`}</b> por montar, de la más vieja a la más nueva.
                        </p>
                        <div className="flex gap-2">
                            <Button
                                fullWidth variant="flat" radius="full"
                                startContent={copied === 'all' ? <CopyCheck size={16} /> : <Copy size={16} />}
                                onPress={() => copy('all', notes.map(mondayText).join('\n\n———\n\n'))}
                            >
                                {copied === 'all' ? 'Copiadas' : 'Copiar todas'}
                            </Button>
                            <Button fullWidth color="primary" radius="full" startContent={<Check size={16} />} onPress={markAll}>
                                Ya monté todo
                            </Button>
                        </div>
                    </Card>

                    <div className="mt-4 flex flex-col gap-3">
                        {notes.map((note) => (
                            <Card key={note.id} className="animate-pop-in flex flex-col gap-2 !py-3">
                                <div>
                                    <p className="font-semibold">{note.client ?? 'Sin cliente'}</p>
                                    <p className="text-[11px] text-default-400">{dayjs(note.created_at).format('ddd D MMM, h:mm a')}</p>
                                </div>
                                {note.body && <NoteBody body={note.body} />}
                                <NoteAttachments files={note.attachments} compact />
                                <div className="mt-1 flex gap-2">
                                    <Button
                                        fullWidth size="sm" variant="flat" radius="full"
                                        startContent={copied === note.id ? <CopyCheck size={15} /> : <Copy size={15} />}
                                        onPress={() => copy(note.id, mondayText(note))}
                                    >
                                        {copied === note.id ? 'Copiado' : 'Copiar'}
                                    </Button>
                                    <Button
                                        fullWidth size="sm" color="success" variant="flat" radius="full"
                                        startContent={<Check size={15} />}
                                        onPress={() => markDone(note)}
                                    >
                                        Ya la monté
                                    </Button>
                                </div>
                            </Card>
                        ))}
                    </div>
                </>
            )}

            {/* El aviso de cada día */}
            <SectionHeader title="Aviso diario" />
            <Card className="flex flex-col gap-3">
                <div className="flex items-center gap-3">
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium">Recordarme lo que falta</p>
                        <p className="text-xs text-default-400">
                            {reminderAt ? `Cada día a las ${reminderAt}, solo si queda algo por montar.` : 'Apagado.'}
                        </p>
                    </div>
                    <Switch
                        size="sm" color="primary" isSelected={!!reminderAt}
                        onValueChange={(on) => saveReminder(on ? time : null)}
                    />
                </div>
                {reminderAt && (
                    <input
                        type="time" value={time}
                        onChange={(e) => setTime(e.target.value)}
                        onBlur={() => time && time !== reminderAt && saveReminder(time)}
                        className="w-full rounded-xl bg-content2 px-3 py-2.5 text-base"
                        aria-label="Hora del aviso"
                    />
                )}
                <NotificationsToggle />
            </Card>
        </AppLayout>
    );
}
