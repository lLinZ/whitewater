import { Download, FileText, X } from 'lucide-react';
import ReceiptViewer from '@/Components/ui/ReceiptViewer';
import { formatSize } from '@/lib/notes';
import type { NoteAttachment } from '@/types';

/**
 * Los archivos de una nota: fotos y garabatos en miniatura (se amplían al
 * tocarlos), audios y vídeos con su reproductor, y el resto para abrir o
 * descargar. Con `onRemove`, cada uno tiene su botón de quitar.
 */
export default function NoteAttachments({ files, onRemove, compact = false }: {
    files: NoteAttachment[];
    onRemove?: (file: NoteAttachment) => void;
    compact?: boolean;
}) {
    if (files.length === 0) return null;

    const images = files.filter((f) => f.kind === 'image');
    const others = files.filter((f) => f.kind !== 'image');

    const remove = (file: NoteAttachment) => onRemove && (
        <button
            type="button" onClick={() => onRemove(file)} aria-label={`Quitar ${file.name}`}
            className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-default-200 text-default-600 active:scale-90"
        >
            <X size={13} />
        </button>
    );

    return (
        <div className="flex flex-col gap-2">
            {images.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {images.map((file) => (
                        <div key={file.id} className="relative">
                            <ReceiptViewer url={file.url} alt={file.name} size={compact ? 52 : 64} />
                            {onRemove && <div className="absolute -right-1.5 -top-1.5">{remove(file)}</div>}
                        </div>
                    ))}
                </div>
            )}
            {others.map((file) => (
                <div key={file.id} className="flex items-center gap-2 rounded-xl bg-content2 px-2.5 py-2">
                    {file.kind === 'audio' ? (
                        // preload="none": una lista con varias llamadas no debe
                        // descargarlas todas al abrirse.
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-[11px] text-default-500">🎙 {file.name}</p>
                            <audio controls preload="none" src={file.url} className="mt-1 h-9 w-full" />
                        </div>
                    ) : file.kind === 'video' ? (
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-[11px] text-default-500">{file.name}</p>
                            <video controls playsInline preload="none" src={file.url} className="mt-1 max-h-60 w-full rounded-lg" />
                        </div>
                    ) : (
                        <a href={file.url} target="_blank" rel="noreferrer" className="flex min-w-0 flex-1 items-center gap-2 active:opacity-60">
                            <FileText size={18} className="shrink-0 text-primary" />
                            <span className="min-w-0">
                                <span className="block truncate text-sm">{file.name}</span>
                                <span className="text-[11px] text-default-400">{formatSize(file.size)}</span>
                            </span>
                        </a>
                    )}
                    <a
                        href={`${file.url}?descargar=1`} aria-label={`Descargar ${file.name}`}
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-default-500 active:bg-default-200"
                    >
                        <Download size={16} />
                    </a>
                    {remove(file)}
                </div>
            ))}
        </div>
    );
}
