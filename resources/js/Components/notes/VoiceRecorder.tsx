import { useEffect, useRef, useState } from 'react';
import { Mic, Square } from 'lucide-react';

/** El primer formato que sepa grabar este navegador. El iPhone graba mp4 (m4a). */
function pickFormat(): { mime: string; ext: string } | null {
    if (typeof window === 'undefined' || !('MediaRecorder' in window) || !navigator.mediaDevices?.getUserMedia) {
        return null;
    }
    const options = [
        { mime: 'audio/mp4', ext: 'm4a' },
        { mime: 'audio/webm;codecs=opus', ext: 'weba' },
        { mime: 'audio/webm', ext: 'weba' },
        { mime: 'audio/ogg', ext: 'ogg' },
    ];
    return options.find((o) => MediaRecorder.isTypeSupported?.(o.mime)) ?? { mime: '', ext: 'm4a' };
}

const FORMAT = pickFormat();

export const canRecordVoice = FORMAT !== null;

/**
 * Nota de voz: para contar lo que pasó en una llamada en vez de escribirlo.
 * Un toque empieza, otro termina; el audio se adjunta a la nota.
 */
export default function VoiceRecorder({ onRecorded, onError }: {
    onRecorded: (file: File) => void;
    onError: (message: string) => void;
}) {
    const recorder = useRef<MediaRecorder | null>(null);
    const chunks = useRef<Blob[]>([]);
    const [seconds, setSeconds] = useState<number | null>(null);

    useEffect(() => {
        if (seconds === null) return;
        const timer = setInterval(() => setSeconds((s) => (s ?? 0) + 1), 1000);
        return () => clearInterval(timer);
    }, [seconds === null]); // eslint-disable-line react-hooks/exhaustive-deps

    // Si se sale a mitad de una grabación, se suelta el micrófono.
    useEffect(() => () => recorder.current?.stream.getTracks().forEach((t) => t.stop()), []);

    const start = async () => {
        if (!FORMAT) return;
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const rec = new MediaRecorder(stream, FORMAT.mime ? { mimeType: FORMAT.mime } : undefined);
            chunks.current = [];
            rec.ondataavailable = (e) => { if (e.data.size > 0) chunks.current.push(e.data); };
            rec.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                const type = rec.mimeType || FORMAT.mime || 'audio/mp4';
                const blob = new Blob(chunks.current, { type });
                const stamp = new Date().toTimeString().slice(0, 5).replace(':', '-');
                if (blob.size > 0) onRecorded(new File([blob], `nota-de-voz-${stamp}.${FORMAT.ext}`, { type }));
                setSeconds(null);
            };
            rec.start();
            recorder.current = rec;
            setSeconds(0);
        } catch {
            onError('No se pudo usar el micrófono. Revisa el permiso en Ajustes → Safari → Micrófono.');
        }
    };

    const stop = () => recorder.current?.stop();

    if (!FORMAT) return null;

    if (seconds !== null) {
        const clock = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
        return (
            <button
                type="button" onClick={stop}
                className="flex items-center gap-1.5 rounded-full bg-rose-500 px-3 py-2 text-xs font-semibold text-white active:scale-95"
            >
                <span className="h-2 w-2 animate-pulse rounded-full bg-white" />
                {clock}
                <Square size={12} fill="currentColor" />
            </button>
        );
    }

    return (
        <button
            type="button" onClick={start} aria-label="Grabar nota de voz"
            className="flex flex-col items-center gap-0.5 rounded-xl px-2 py-1 text-[10px] text-default-500 active:bg-default-100"
        >
            <Mic size={20} /> Voz
        </button>
    );
}
