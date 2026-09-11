import axios from 'axios';
import type { Note, NoteAttachment } from '@/types';

/**
 * El texto que se pega en Monday, con el mismo formato con que se anota a
 * mano: "Nombre del cliente: … / Nota: …".
 */
export function mondayText(note: Pick<Note, 'client' | 'body' | 'attachments'>): string {
    const lines = [
        `Nombre del cliente: ${note.client ?? '—'}`,
        `Nota: ${note.body ?? ''}`.trimEnd(),
    ];
    const files = note.attachments?.length ?? 0;
    if (files > 0) {
        lines.push(`(${files} ${files === 1 ? 'archivo' : 'archivos'} en Whitewater)`);
    }
    return lines.join('\n');
}

/**
 * Copia al portapapeles. El API moderno pide contexto seguro; el textarea es
 * el recurso para cuando no está (o en Safari antiguos).
 */
export async function copyText(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        return ok;
    }
}

export function formatSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/** Límite por archivo; el servidor rechaza lo que pase de aquí. */
export const MAX_FILE_BYTES = 20 * 1024 * 1024;

/** Sube un archivo a una nota, avisando del progreso de 0 a 100. */
export async function uploadNoteFile(
    noteId: number,
    file: File,
    onProgress: (percent: number) => void,
): Promise<NoteAttachment> {
    const form = new FormData();
    form.append('file', file);

    const { data } = await axios.post<NoteAttachment>(`/herramientas/notas/${noteId}/archivos`, form, {
        onUploadProgress: (event) => {
            if (event.total) onProgress(Math.round((event.loaded / event.total) * 100));
        },
    });

    return data;
}

/** El primer mensaje de error de una respuesta de validación de Laravel. */
export function errorMessage(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
        const first = errors ? Object.values(errors)[0]?.[0] : undefined;
        if (first) return first;
        if (error.response?.status === 413) return 'El archivo es demasiado grande para el servidor.';
        if (!error.response) return 'Sin conexión. Lo escrito sigue aquí; vuelve a intentarlo.';
    }
    return fallback;
}

/** Parte un texto en trozos normales y #etiquetas, para pintarlas distinto. */
export function splitTags(text: string): { text: string; tag?: string }[] {
    return text
        .split(/((?<![\p{L}\p{N}_])#[\p{L}\p{N}_-]+)/u)
        .filter(Boolean)
        .map((part) => (part.startsWith('#') && part.length > 1
            ? { text: part, tag: part.slice(1).toLowerCase() }
            : { text: part }));
}
