import { useEffect, useRef, useState } from 'react';
import { Camera, X } from 'lucide-react';

interface Props {
    value: File | null;
    onChange: (file: File | null) => void;
    /** Comprobante ya guardado, al editar un registro que existe. */
    currentUrl?: string | null;
    /** true = se pidió quitar el que ya estaba guardado. */
    removed?: boolean;
    onRemovedChange?: (removed: boolean) => void;
    error?: string;
    label?: string;
    hint?: string;
}

/**
 * Adjuntar la foto del comprobante (transferencia del banco, ticket del súper).
 *
 * Siempre opcional: si no se tiene el recibo a mano, el movimiento se registra
 * igual y la foto se añade después editándolo.
 */
export default function ReceiptPicker({
    value,
    onChange,
    currentUrl = null,
    removed = false,
    onRemovedChange,
    error,
    label = 'Comprobante',
    hint = 'Foto del recibo (opcional)',
}: Props) {
    const input = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);

    // La URL del objeto hay que liberarla, o cada foto elegida se queda en
    // memoria hasta recargar la página.
    useEffect(() => {
        if (!value) {
            setPreview(null);
            return;
        }
        const url = URL.createObjectURL(value);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [value]);

    const pick = () => input.current?.click();

    const choose = (file: File | null) => {
        onChange(file);
        // Elegir una foto nueva sustituye a la vieja, no la deja "quitada".
        onRemovedChange?.(false);
    };

    /** Descarta la elección actual y vuelve al estado sin comprobante. */
    const clear = () => {
        onChange(null);
        if (input.current) input.current.value = '';
        if (preview === null && currentUrl) onRemovedChange?.(true);
    };

    /** Quita el que ya estaba guardado en el servidor. */
    const removeCurrent = () => {
        onChange(null);
        if (input.current) input.current.value = '';
        onRemovedChange?.(true);
    };

    // El guardado se ve solo si no lo han quitado ni elegido otro encima.
    const saved = currentUrl && !removed && !preview ? currentUrl : null;
    const shown = preview ?? saved;

    return (
        <div>
            <input
                ref={input}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => choose(e.target.files?.[0] ?? null)}
            />

            {shown ? (
                <div className="flex items-center gap-3 rounded-2xl border border-divider p-2">
                    <img
                        src={shown}
                        alt="Comprobante"
                        className="h-14 w-14 rounded-xl object-cover"
                    />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">
                            {preview ? value?.name : 'Comprobante guardado'}
                        </p>
                        <button type="button" onClick={pick} className="text-xs text-primary">
                            Cambiar foto
                        </button>
                    </div>
                    <button
                        type="button"
                        onClick={preview ? clear : removeCurrent}
                        aria-label="Quitar comprobante"
                        className="flex h-8 w-8 items-center justify-center rounded-full bg-default-100 text-default-500 active:scale-90"
                    >
                        <X size={16} />
                    </button>
                </div>
            ) : (
                <button
                    type="button"
                    onClick={pick}
                    className="flex w-full items-center gap-3 rounded-2xl border border-dashed border-divider px-3 py-3 text-left active:scale-[0.99]"
                >
                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Camera size={18} />
                    </span>
                    <span className="min-w-0">
                        <span className="block text-sm font-medium">{label}</span>
                        <span className="block text-xs text-default-400">
                            {removed && currentUrl ? 'Se quitará al guardar' : hint}
                        </span>
                    </span>
                </button>
            )}

            {error && <p className="mt-1 px-1 text-xs text-rose-500">{error}</p>}
        </div>
    );
}
