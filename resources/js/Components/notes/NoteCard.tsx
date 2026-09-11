import { router } from '@inertiajs/react';
import { CheckCircle2, Circle } from 'lucide-react';
import { Card } from '@/Components/ui/primitives';
import NoteAttachments from '@/Components/notes/NoteAttachments';
import { dayjs } from '@/lib/format';
import { splitTags } from '@/lib/notes';
import type { Note } from '@/types';

/** El texto con las #etiquetas resaltadas; tocar una filtra por ella. */
export function NoteBody({ body, onTag }: { body: string; onTag?: (tag: string) => void }) {
    return (
        <p className="whitespace-pre-wrap break-words text-[15px] leading-relaxed">
            {splitTags(body).map((part, i) => (part.tag ? (
                <button
                    key={i} type="button"
                    onClick={(e) => { e.stopPropagation(); onTag?.(part.tag!); }}
                    className="font-medium text-primary"
                >
                    {part.text}
                </button>
            ) : <span key={i}>{part.text}</span>))}
        </p>
    );
}

/**
 * Una nota en la lista. Tocarla la abre para editar; el círculo de la
 * esquina la marca como montada en Monday sin abrirla.
 */
export default function NoteCard({ note, onOpen, onTag }: {
    note: Note;
    onOpen: (note: Note) => void;
    onTag: (tag: string) => void;
}) {
    const done = !!note.monday_at;

    const toggle = (e: React.MouseEvent) => {
        e.stopPropagation();
        router.patch(`/herramientas/notas/${note.id}/monday`, { done: !done }, { preserveScroll: true, preserveState: true });
    };

    return (
        <Card className={`flex flex-col gap-2 !py-3 ${done ? 'opacity-60' : ''}`} onClick={() => onOpen(note)}>
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold">{note.client ?? 'Sin cliente'}</p>
                    <p className="text-[11px] text-default-400">
                        {dayjs(note.created_at).format('h:mm a')}
                        {done && ` · en Monday ${dayjs(note.monday_at).format('D MMM')}`}
                    </p>
                </div>
                <button
                    onClick={toggle} aria-pressed={done}
                    aria-label={done ? 'Marcar como pendiente de Monday' : 'Marcar como montada en Monday'}
                    className={`-m-1 flex shrink-0 items-center gap-1 rounded-full p-1 text-[11px] font-medium ${done ? 'text-emerald-500' : 'text-default-400'}`}
                >
                    {done ? <CheckCircle2 size={22} /> : <Circle size={22} />}
                </button>
            </div>
            {note.body && <NoteBody body={note.body} onTag={onTag} />}
            {note.attachments.length > 0 && (
                // Los reproductores y miniaturas no abren la edición al tocarlos.
                <div onClick={(e) => e.stopPropagation()}>
                    <NoteAttachments files={note.attachments} compact />
                </div>
            )}
        </Card>
    );
}
