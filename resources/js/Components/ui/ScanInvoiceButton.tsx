import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ScanLine } from 'lucide-react';

/**
 * Botón que abre la cámara y manda la foto de la factura a leer.
 *
 * Con `tripId`, la factura se suma a ese mercado en vez de crear uno nuevo:
 * es lo que pasa cuando una salida de compras pasa por varios supermercados.
 */
export default function ScanInvoiceButton({ tripId, label, className = '' }: {
    tripId?: number;
    label: string;
    className?: string;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [scanning, setScanning] = useState(false);

    // La lectura tarda unos segundos: el estado de carga es lo único que
    // separa "está pensando" de "no pasó nada".
    const scan = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setScanning(true);
        router.post('/mercado/escanear', tripId ? { invoice: file, trip: tripId } : { invoice: file }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                setScanning(false);
                if (input.current) input.current.value = '';
            },
        });
    };

    return (
        <div className={className}>
            <input ref={input} type="file" accept="image/*" className="hidden" onChange={scan} />
            <button
                onClick={() => input.current?.click()}
                disabled={scanning}
                className="flex w-full items-center justify-center gap-2 rounded-3xl border border-dashed border-divider py-3.5 text-sm font-semibold text-default-600 active:scale-[0.99] disabled:opacity-60"
            >
                <ScanLine size={18} className={scanning ? 'animate-pulse text-primary' : 'text-primary'} />
                {scanning ? 'Leyendo la factura…' : label}
            </button>
            {scanning && (
                <p className="mt-1.5 text-center text-xs text-default-400">
                    Puede tardar unos segundos. No cierres la app.
                </p>
            )}
        </div>
    );
}
