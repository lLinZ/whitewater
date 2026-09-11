import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Autocomplete, AutocompleteItem, Button, Textarea } from '@heroui/react';
import { Camera, Check, Paperclip, PenLine, RotateCw, X } from 'lucide-react';
import SketchPad from '@/Components/notes/SketchPad';
import VoiceRecorder from '@/Components/notes/VoiceRecorder';
import NoteAttachments from '@/Components/notes/NoteAttachments';
import { errorMessage, formatSize, MAX_FILE_BYTES, uploadNoteFile } from '@/lib/notes';
import type { Note, NoteAttachment } from '@/types';

/** Un archivo elegido que aún no ha subido (o que falló al subir). */
interface Pending {
    key: number;
    file: File;
    preview: string | null;
    progress: number | null;
    failed: boolean;
}

const DRAFT_KEY = 'whitewater.noteDraft';

function readDraft(): { client: string; body: string } {
    try {
        const draft = JSON.parse(localStorage.getItem(DRAFT_KEY) ?? '{}');
        return { client: draft.client ?? '', body: draft.body ?? '' };
    } catch {
        return { client: '', body: '' };
    }
}

function writeDraft(client: string, body: string) {
    try {
        if (client || body) localStorage.setItem(DRAFT_KEY, JSON.stringify({ client, body }));
        else localStorage.removeItem(DRAFT_KEY);
    } catch { /* sin almacenamiento: no hay borrador, nada más */ }
}

/**
 * Escribir una nota nueva o editar una que ya existe.
 *
 * Hecho para anotar con el teléfono en una mano y la llamada en la otra:
 * - El texto nuevo se guarda solo en el teléfono mientras se escribe: si la
 *   app se cierra o se va la señal, al volver sigue ahí.
 * - Las etiquetas más usadas están a un toque.
 * - Los archivos se suben de uno en uno con su progreso; si uno falla, la
 *   nota ya está guardada y ese archivo se puede reintentar sin reescribir.
 */
export default function NoteComposer({ note = null, clients, tags, onSaved }: {
    note?: Note | null;
    clients: string[];
    tags: string[];
    onSaved?: () => void;
}) {
    const editing = !!note;
    const [client, setClient] = useState(() => note?.client ?? (editing ? '' : readDraft().client));
    const [body, setBody] = useState(() => note?.body ?? (editing ? '' : readDraft().body));
    const [saved, setSaved] = useState<NoteAttachment[]>(note?.attachments ?? []);
    const [pending, setPending] = useState<Pending[]>([]);
    // La nota ya creada cuando algún archivo falló: reintentar lo cuelga de ella.
    const [noteId, setNoteId] = useState<number | null>(note?.id ?? null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [sketching, setSketching] = useState(false);
    const textRef = useRef<HTMLTextAreaElement>(null);
    const photoInput = useRef<HTMLInputElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const nextKey = useRef(1);

    useEffect(() => {
        if (!editing) writeDraft(client, body);
    }, [client, body, editing]);

    // Las miniaturas locales ocupan memoria hasta que se sueltan.
    useEffect(() => () => pending.forEach((p) => p.preview && URL.revokeObjectURL(p.preview)), []); // eslint-disable-line react-hooks/exhaustive-deps

    const addFiles = (files: File[]) => {
        const tooBig = files.filter((f) => f.size > MAX_FILE_BYTES);
        if (tooBig.length) setError(`${tooBig.map((f) => f.name).join(', ')}: pasa de 20 MB.`);
        const ok = files.filter((f) => f.size <= MAX_FILE_BYTES);
        setPending((current) => [
            ...current,
            ...ok.map((file) => ({
                key: nextKey.current++,
                file,
                preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
                progress: null,
                failed: false,
            })),
        ]);
    };

    const dropPending = (key: number) => setPending((current) => {
        const gone = current.find((p) => p.key === key);
        if (gone?.preview) URL.revokeObjectURL(gone.preview);
        return current.filter((p) => p.key !== key);
    });

    /** Inserta "#etiqueta " donde está el cursor. */
    const insertTag = (tag: string) => {
        const area = textRef.current;
        const at = area?.selectionStart ?? body.length;
        const before = body.slice(0, at);
        const glue = before && !/\s$/.test(before) ? ' ' : '';
        const next = `${before}${glue}#${tag} ${body.slice(at)}`;
        setBody(next);
        requestAnimationFrame(() => {
            const pos = before.length + glue.length + tag.length + 2;
            area?.focus();
            area?.setSelectionRange(pos, pos);
        });
    };

    const removeSaved = async (file: NoteAttachment) => {
        if (!confirm(`¿Quitar "${file.name}" de la nota?`)) return;
        try {
            await axios.delete(file.url);
            setSaved((current) => current.filter((f) => f.id !== file.id));
        } catch (e) {
            setError(errorMessage(e, 'No se pudo quitar el archivo.'));
        }
    };

    const reset = () => {
        pending.forEach((p) => p.preview && URL.revokeObjectURL(p.preview));
        setClient('');
        setBody('');
        setPending([]);
        setNoteId(null);
        writeDraft('', '');
    };

    const save = async () => {
        if (busy) return;
        setBusy(true);
        setError(null);

        try {
            const payload = { client, body, has_files: pending.length > 0 || saved.length > 0 };
            // Si ya existe (se está editando, o se reintenta tras un archivo
            // que falló) se actualiza: lo retocado entretanto también cuenta.
            let id = noteId;
            if (id) {
                await axios.patch(`/herramientas/notas/${id}`, payload);
            } else {
                id = (await axios.post<{ id: number }>('/herramientas/notas', payload)).data.id;
                setNoteId(id);
            }

            // Uno a uno: una llamada larga por datos móviles no debe tumbar
            // al resto, y cada uno enseña cuánto le falta.
            let failures = 0;
            for (const item of pending) {
                const patch = (changes: Partial<Pending>) =>
                    setPending((current) => current.map((p) => (p.key === item.key ? { ...p, ...changes } : p)));
                try {
                    patch({ progress: 0, failed: false });
                    const uploaded = await uploadNoteFile(id!, item.file, (progress) => patch({ progress }));
                    setSaved((current) => [...current, uploaded]);
                    dropPending(item.key);
                } catch (e) {
                    failures++;
                    patch({ progress: null, failed: true });
                    setError(errorMessage(e, `No subió "${item.file.name}".`));
                }
            }

            router.reload({ only: ['notes', 'tags', 'clients', 'notesPending'] });

            if (failures === 0) {
                if (!editing) reset();
                onSaved?.();
            } else if (!editing) {
                setError((current) => `La nota se guardó, pero ${failures === 1 ? 'un archivo no subió' : `${failures} archivos no subieron`}. ${current ?? ''} Toca "Reintentar".`);
            }
        } catch (e) {
            setError(errorMessage(e, 'No se pudo guardar la nota.'));
        } finally {
            setBusy(false);
        }
    };

    const failedUploads = pending.some((p) => p.failed);
    const empty = !client.trim() && !body.trim() && pending.length === 0 && saved.length === 0;
    const suggestions = tags.filter((t) => !body.toLowerCase().includes(`#${t}`)).slice(0, 10);

    return (
        <div className="flex flex-col gap-2.5">
            <Autocomplete
                aria-label="Cliente" placeholder="Nombre del cliente" allowsCustomValue menuTrigger="input"
                variant="flat" radius="lg"
                inputValue={client}
                onInputChange={setClient}
                onSelectionChange={(key) => key && setClient(String(key))}
                inputProps={{ autoComplete: 'off', autoCapitalize: 'words', classNames: { input: 'text-base' } }}
            >
                {clients.map((c) => <AutocompleteItem key={c}>{c}</AutocompleteItem>)}
            </Autocomplete>

            {suggestions.length > 0 && (
                <div className="hide-scrollbar -mx-1 flex gap-1.5 overflow-x-auto px-1">
                    {suggestions.map((tag) => (
                        <button
                            key={tag} type="button" onClick={() => insertTag(tag)}
                            className="shrink-0 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary active:scale-95"
                        >
                            #{tag}
                        </button>
                    ))}
                </div>
            )}

            <Textarea
                ref={textRef}
                aria-label="Nota" placeholder="¿Qué pasó? Usa #etiquetas: #dryout, #confirmado…"
                variant="flat" radius="lg" minRows={3} maxRows={14}
                value={body} onValueChange={setBody}
                onKeyDown={(e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); save(); } }}
                // 16px: con menos, Safari del iPhone acerca la pantalla al escribir.
                classNames={{ input: 'text-base leading-relaxed' }}
            />

            {/* Lo ya guardado (al editar) y lo que está por subir */}
            <NoteAttachments files={saved} onRemove={removeSaved} compact />
            {pending.length > 0 && (
                <div className="flex flex-col gap-1.5">
                    {pending.map((p) => (
                        <div key={p.key} className="flex items-center gap-2 rounded-xl bg-content2 px-2.5 py-2">
                            {p.preview
                                ? <img src={p.preview} alt="" className="h-9 w-9 shrink-0 rounded-lg object-cover" />
                                : <Paperclip size={16} className="shrink-0 text-default-400" />}
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-xs">{p.file.name}</p>
                                {p.progress !== null ? (
                                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-default-200">
                                        <div className="h-full rounded-full bg-primary transition-all" style={{ width: `${p.progress}%` }} />
                                    </div>
                                ) : (
                                    <p className={`text-[11px] ${p.failed ? 'text-rose-500' : 'text-default-400'}`}>
                                        {p.failed ? 'No subió' : `${formatSize(p.file.size)} · se sube al guardar`}
                                    </p>
                                )}
                            </div>
                            {p.progress === null && (
                                <button type="button" onClick={() => dropPending(p.key)} aria-label="Quitar" className="text-default-400 active:text-rose-500">
                                    <X size={16} />
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {error && <p className="px-1 text-xs text-rose-500">{error}</p>}

            <div className="flex items-center gap-1">
                <button type="button" onClick={() => setSketching(true)} className="flex flex-col items-center gap-0.5 rounded-xl px-2 py-1 text-[10px] text-default-500 active:bg-default-100">
                    <PenLine size={20} /> Garabato
                </button>
                <button type="button" onClick={() => photoInput.current?.click()} className="flex flex-col items-center gap-0.5 rounded-xl px-2 py-1 text-[10px] text-default-500 active:bg-default-100">
                    <Camera size={20} /> Foto
                </button>
                <button type="button" onClick={() => fileInput.current?.click()} className="flex flex-col items-center gap-0.5 rounded-xl px-2 py-1 text-[10px] text-default-500 active:bg-default-100">
                    <Paperclip size={20} /> Archivo
                </button>
                <VoiceRecorder onRecorded={(file) => addFiles([file])} onError={setError} />

                <Button
                    className="ml-auto" color="primary" radius="full"
                    startContent={!busy && (failedUploads ? <RotateCw size={16} /> : <Check size={16} />)}
                    isLoading={busy} isDisabled={empty}
                    onPress={save}
                >
                    {failedUploads ? 'Reintentar' : editing ? 'Guardar cambios' : 'Guardar'}
                </Button>
            </div>

            {/* image/*: el iPhone ofrece cámara o fototeca. El de archivos abre Archivos, donde se guardan las grabaciones. */}
            <input
                ref={photoInput} type="file" accept="image/*" multiple className="hidden"
                onChange={(e) => { addFiles(Array.from(e.target.files ?? [])); e.target.value = ''; }}
            />
            <input
                ref={fileInput} type="file" multiple className="hidden"
                accept="audio/*,video/*,image/*,application/pdf,text/plain,.m4a,.mp3,.amr,.caf"
                onChange={(e) => { addFiles(Array.from(e.target.files ?? [])); e.target.value = ''; }}
            />

            {sketching && (
                <SketchPad
                    onCancel={() => setSketching(false)}
                    onDone={(file) => { addFiles([file]); setSketching(false); }}
                />
            )}
        </div>
    );
}
