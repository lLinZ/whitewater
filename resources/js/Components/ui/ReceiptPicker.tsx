import { useEffect, useRef, useState } from 'react';
import { Camera, X } from 'lucide-react';

interface Props {
    value: File | null;
    onChange: (file: File | null) => void;
    error?: string;
    label?: string;
    hint?: string;
}

/**
 * Adjuntar la foto del comprobante (transferencia del banco, ticket del súper).
 *
 * Siempre opcional: si no se tiene el recibo a mano, el movimiento se registra
 * igual y la foto se puede añadir después borrando y volviendo a crearlo.
 */
export default function ReceiptPicker({
    value,
    onChange,
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

    const clear = () => {
        onChange(null);
        if (input.current) input.current.value = '';
    };

    return (
        <div>
            <input
                ref={input}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => onChange(e.target.files?.[0] ?? null)}
            />

            {preview ? (
                <div className="flex items-center gap-3 rounded-2xl border border-divider p-2">
                    <img src={preview} alt="Comprobante elegido" className="h-14 w-14 rounded-xl object-cover" />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">{value?.name}</p>
                        <button type="button" onClick={() => input.current?.click()} className="text-xs text-primary">
                            Cambiar
                        </button>
                    </div>
                    <button
                        type="button" onClick={clear} aria-label="Quitar comprobante"
                        className="flex h-8 w-8 items-center justify-center rounded-full bg-default-100 text-default-500 active:scale-90"
                    >
                        <X size={16} />
                    </button>
                </div>
            ) : (
                <button
                    type="button"
                    onClick={() => input.current?.click()}
                    className="flex w-full items-center gap-3 rounded-2xl border border-dashed border-divider px-3 py-3 text-left active:scale-[0.99]"
                >
                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Camera size={18} />
                    </span>
                    <span className="min-w-0">
                        <span className="block text-sm font-medium">{label}</span>
                        <span className="block text-xs text-default-400">{hint}</span>
                    </span>
                </button>
            )}

            {error && <p className="mt-1 px-1 text-xs text-rose-500">{error}</p>}
        </div>
    );
}
