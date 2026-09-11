import { useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Button, Input, Switch } from '@heroui/react';
import { AlertTriangle, Check, Plus, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, SectionHeader } from '@/Components/ui/primitives';
import DecimalInput from '@/Components/ui/DecimalInput';
import ReceiptViewer from '@/Components/ui/ReceiptViewer';
import { formatBs, formatMoney, formatUsdt, parseDecimal, today } from '@/lib/format';
import { accent } from '@/lib/accent';
import { PageProps, RateSnapshot } from '@/types';

interface ScannedItem {
    name: string;
    brand: string | null;
    size: string | null;
    quantity: number;
    unit_price: number;
    /** Si paga IVA según el ticket ((G) o (E)); null si no lo marca. */
    taxed: boolean | null;
}

interface Invoice {
    store: string | null;
    date: string | null;
    currency: 'VES' | 'USD' | 'EUR';
    items: ScannedItem[];
    subtotal: number | null;
    tax: number | null;
    total: number | null;
    items_total: number;
    confidence: 'alta' | 'media' | 'baja';
    notes: string | null;
}

interface Props {
    invoice: Invoice;
    receiptUrl: string;
    /** Con las que se convierte: las del mercado al que se suma, o las de hoy. */
    rates: RateSnapshot | null;
    /** Mercado en curso al que se suma la factura; null si crea uno nuevo. */
    trip: { id: number; name: string; item_count: number; total_usd: number } | null;
}

/** Fila editable: los precios se guardan como texto hasta el envío. */
interface Draft {
    key: number;
    name: string;
    brand: string;
    size: string;
    quantity: string;
    price: string;
    taxed: boolean | null;
}

const CONFIDENCE: Record<Invoice['confidence'], { label: string; tone: string }> = {
    alta: { label: 'Lectura clara', tone: 'text-emerald-600 dark:text-emerald-400' },
    media: { label: 'Revisa los precios', tone: 'text-amber-600 dark:text-amber-400' },
    baja: { label: 'Lectura dudosa: revísalo todo', tone: 'text-rose-600 dark:text-rose-400' },
};

export default function MarketInvoice({ invoice, receiptUrl, rates, trip }: Props) {
    const user = usePage<PageProps>().props.auth.user;
    const inBolivares = invoice.currency === 'VES';

    const [name, setName] = useState('');
    const [store, setStore] = useState(invoice.store ?? '');
    const [date, setDate] = useState(invoice.date ?? today());
    // El IVA se reparte por defecto: lo que le importa al presupuesto del
    // hogar es lo que salio del bolsillo, no el precio de estante.
    const [includeTax, setIncludeTax] = useState(true);
    const [processing, setProcessing] = useState(false);
    const [items, setItems] = useState<Draft[]>(() =>
        invoice.items.map((item, i) => ({
            key: i,
            name: item.name,
            brand: item.brand ?? '',
            size: item.size ?? '',
            quantity: String(item.quantity),
            price: String(item.unit_price),
            taxed: item.taxed ?? null,
        })),
    );

    // Siempre a BCV: el mercado pasa sus dólares a bolívares con la tasa BCV,
    // y guardar a otra tasa haría que mostrara bolívares que no se pagaron.
    // El paralelo solo sirve para decir cuánto es en USDT.
    const rate = rates?.bcv_usd ?? null;
    const parallel = rates?.parallel_usd ?? null;

    // Base imponible declarada: el subtotal, o el total menos el IVA.
    const declaredSubtotal = invoice.subtotal
        ?? (invoice.total !== null && invoice.tax !== null ? invoice.total - invoice.tax : null);
    const hasTax = (invoice.tax ?? 0) > 0 && declaredSubtotal !== null && declaredSubtotal > 0;

    /*
     * Cómo se reparte el IVA. Los tickets fiscales marcan cada línea con (G)
     * o (E): entonces el IVA va solo a las gravadas, a la alícuota que sale de
     * la propia factura (IVA / base de las gravadas). Si el ticket no lo marca
     * se reparte parejo, en proporción total / base.
     *
     * Las dos se anclan a lo leído y no a lo que hay en pantalla: así corregir
     * un precio no deforma el resto.
     */
    const taxedBase = invoice.items
        .filter((i) => i.taxed)
        .reduce((sum, i) => sum + i.unit_price * i.quantity, 0);
    const taxRate = hasTax && taxedBase > 0 ? (invoice.tax as number) / taxedBase : 0;
    // Una alícuota fuera de lo posible en Venezuela (16 %, 8 %, 31 % las de
    // lujo) es señal de que las marcas se leyeron mal: mejor repartir parejo.
    const perItem = invoice.items.some((i) => i.taxed !== null) && taxRate > 0 && taxRate <= 0.35;
    const uniformFactor = hasTax && invoice.total ? invoice.total / (declaredSubtotal as number) : 1;

    /** Cuánto sube una línea al repartirle el IVA. */
    const factorFor = (taxed: boolean | null): number => {
        if (!includeTax || !hasTax) return 1;
        if (perItem) return taxed ? 1 + taxRate : 1;
        return uniformFactor;
    };

    /** Pasa un monto de la factura a dólares. En dólares no se convierte. */
    const toUsd = (value: number): number => {
        if (!inBolivares) return value;
        if (!rate || rate <= 0) return 0;
        return value / rate;
    };

    const rows = useMemo(
        () => items.map((item) => {
            const price = parseDecimal(item.price) ?? 0;
            const quantity = parseDecimal(item.quantity) ?? 1;
            const factor = factorFor(item.taxed);
            const usd = toUsd(price * factor);
            return { ...item, price, quantity, factor, usd, subtotal: usd * quantity };
        }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [items, rate, inBolivares, includeTax, perItem, taxRate, uniformFactor],
    );

    // Suma de las líneas tal como vienen en la factura, sin IVA repartido.
    const linesTotal = rows.reduce((sum, r) => sum + r.price * r.quantity, 0);
    const totalOriginal = rows.reduce((sum, r) => sum + r.price * r.quantity * r.factor, 0);
    const totalUsd = rows.reduce((sum, r) => sum + r.subtotal, 0);
    const taxedCount = rows.filter((r) => r.taxed).length;

    // El descuadre se mide contra la base imponible, no contra el total: en una
    // factura con IVA las líneas nunca suman el total, y avisar de eso siempre
    // sería enseñar a ignorar el aviso.
    const base = declaredSubtotal ?? invoice.total;
    const mismatch = base !== null && Math.abs(base - linesTotal) > Math.max(1, base * 0.02);

    const patch = (key: number, changes: Partial<Draft>) =>
        setItems((current) => current.map((i) => (i.key === key ? { ...i, ...changes } : i)));

    const remove = (key: number) => setItems((current) => current.filter((i) => i.key !== key));

    const add = () => setItems((current) => [
        ...current,
        { key: Math.max(0, ...current.map((i) => i.key)) + 1, name: '', brand: '', size: '', quantity: '1', price: '', taxed: null },
    ]);

    const discard = () => {
        if (confirm('¿Descartar esta factura? Se perderá lo leído y la foto.')) {
            router.delete('/mercado/factura');
        }
    };

    const confirmScan = () => {
        setProcessing(true);
        router.post('/mercado/factura', {
            name,
            store,
            date,
            items: rows
                .filter((r) => r.name.trim() !== '')
                .map((r) => ({
                    name: r.name,
                    brand: r.brand || null,
                    size: r.size || null,
                    quantity: r.quantity,
                    // Se manda en dólares: la app lleva todo en dólares y la
                    // tasa elegida ya está aplicada aquí.
                    unit_price_usd: Number(r.usd.toFixed(2)),
                })),
        }, {
            onFinish: () => setProcessing(false),
        });
    };

    const usable = rows.some((r) => r.name.trim() !== '');
    const missingRate = inBolivares && (!rate || rate <= 0);
    const confidence = CONFIDENCE[invoice.confidence];

    return (
        <AppLayout
            title="Factura escaneada"
            subtitle={trip ? `Se suma a «${trip.name}»` : 'Revisa antes de guardar'}
            back={trip ? `/mercado/${trip.id}` : '/mercado'}
        >
            <Head title="Factura escaneada" />

            {/* Lo leído, junto a la foto para poder contrastar */}
            <Card className="flex items-center gap-3">
                <ReceiptViewer url={receiptUrl} alt="Factura escaneada" size={56} />
                <div className="min-w-0 flex-1">
                    <p className={`text-sm font-semibold ${confidence.tone}`}>{confidence.label}</p>
                    <p className="text-xs text-default-400">
                        {invoice.items.length} productos leídos · toca la foto para ampliarla
                    </p>
                    {invoice.notes && <p className="mt-1 text-xs text-default-400">{invoice.notes}</p>}
                </div>
            </Card>

            {mismatch && (
                <Card className="mt-3 flex items-start gap-2 border border-amber-500/40 !py-3">
                    <AlertTriangle size={16} className="mt-0.5 shrink-0 text-amber-500" />
                    <p className="text-xs text-default-600">
                        La factura dice <b>{inBolivares ? formatBs(base) : formatMoney(base)}</b> antes de IVA,
                        pero los productos suman <b>{inBolivares ? formatBs(linesTotal) : formatMoney(linesTotal)}</b>.
                        Puede faltar una línea o estar mal leída.
                    </p>
                </Card>
            )}

            {/* Datos de la compra */}
            {/* Sumándose a un mercado, el nombre ya lo tiene el mercado */}
            <SectionHeader title={trip ? 'La factura' : 'La compra'} />
            <Card className="flex flex-col gap-3">
                {!trip && (
                    <Input label="Nombre" placeholder={`Mercado ${date.slice(8, 10)}/${date.slice(5, 7)}`}
                        value={name} onValueChange={setName} />
                )}
                <Input label="Comercio" value={store} onValueChange={setStore} />
                <Input type="date" label="Fecha" value={date} onValueChange={setDate} />
            </Card>

            {/* Tasa: solo si la factura vino en bolívares */}
            {inBolivares && (
                <>
                    <SectionHeader title="Tasa de cambio" />
                    <Card className="flex flex-col gap-3">
                        <div className="grid grid-cols-2 gap-2 text-center">
                            <div className="rounded-2xl bg-content2 py-2">
                                <p className="text-[11px] text-default-500">BCV</p>
                                <p className="text-sm font-semibold">{rate ? formatBs(rate) : 'sin tasa'}</p>
                            </div>
                            <div className="rounded-2xl bg-content2 py-2">
                                <p className="text-[11px] text-default-500">USDT</p>
                                <p className="text-sm font-semibold">{parallel ? formatBs(parallel) : 'sin tasa'}</p>
                            </div>
                        </div>
                        <p className="text-xs text-default-500">
                            Los precios se guardan en dólares a tasa BCV, como en el resto de la app, así el
                            mercado muestra exactamente los bolívares de la factura. Abajo ves cuánto es en USDT.
                        </p>
                        {trip && (
                            <p className="text-xs text-default-400">Son las tasas de «{trip.name}», para que todo el mercado cuadre.</p>
                        )}
                        {missingRate && (
                            <p className="text-xs text-rose-500">
                                No hay tasa guardada. Actualízala desde el Inicio antes de continuar.
                            </p>
                        )}
                    </Card>
                </>
            )}

            {/* IVA */}
            {hasTax && (
                <>
                    <SectionHeader title="IVA" />
                    <Card className="flex items-center gap-3 !py-3">
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium">Repartir el IVA entre los productos</p>
                            <p className="text-xs text-default-400">
                                {!includeTax
                                    ? 'Los precios quedan sin IVA, como en el estante.'
                                    : perItem
                                        ? `El ${Math.round(taxRate * 100)}% va solo a los ${taxedCount} productos que lo pagan; los exentos no cambian.`
                                        : `Cada precio sube un ${Math.round((uniformFactor - 1) * 100)}%: es lo que pagaste de verdad.`}
                            </p>
                        </div>
                        <Switch size="sm" color="primary" isSelected={includeTax} onValueChange={setIncludeTax} />
                    </Card>
                </>
            )}

            {/* Totales */}
            <Card className={`mt-3 bg-gradient-to-br text-white ${accent(user.color).gradient}`}>
                <p className="text-xs opacity-90">{trip ? 'Total de esta factura' : 'Total de la compra'}</p>
                <p className="mt-1 text-3xl font-bold">{formatMoney(totalUsd)}</p>
                {inBolivares && (
                    <p className="mt-0.5 text-xs opacity-80">
                        {formatBs(totalOriginal)}
                        {parallel ? ` · ${formatUsdt(totalOriginal / parallel)}` : ''}
                        {hasTax && (includeTax ? ' · IVA incluido' : ' · sin IVA')}
                    </p>
                )}
                {trip && (
                    <p className="mt-2 rounded-2xl bg-white/15 px-3 py-2 text-xs">
                        {trip.name} ya lleva {formatMoney(trip.total_usd)} en {trip.item_count} productos:
                        quedará en <b>{formatMoney(trip.total_usd + totalUsd)}</b>.
                    </p>
                )}
            </Card>

            {/* Productos */}
            <SectionHeader
                title={`Productos (${rows.length})`}
                action={
                    <button onClick={add} className="flex items-center gap-1 text-xs font-medium text-primary">
                        <Plus size={14} /> Añadir
                    </button>
                }
            />
            <div className="flex flex-col gap-2">
                {rows.map((row) => (
                    <Card key={row.key} className="flex flex-col gap-2 !py-3">
                        <div className="flex items-start gap-2">
                            <Input
                                size="sm" label="Producto" value={row.name}
                                onValueChange={(v) => patch(row.key, { name: v })}
                            />
                            {/* La marca (G)/(E) del ticket, por si el lector la confundió */}
                            {hasTax && perItem && (
                                <button
                                    onClick={() => patch(row.key, { taxed: !row.taxed })}
                                    aria-pressed={!!row.taxed}
                                    className={`mt-2.5 shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium transition active:scale-95 ${
                                        row.taxed ? 'bg-primary/15 text-primary' : 'bg-default-100 text-default-500'
                                    }`}
                                >
                                    {row.taxed ? 'Con IVA' : 'Exento'}
                                </button>
                            )}
                            <button
                                onClick={() => remove(row.key)} aria-label="Quitar producto"
                                className="mt-2 shrink-0 text-default-300 active:text-rose-500"
                            >
                                <Trash2 size={16} />
                            </button>
                        </div>
                        <div className="flex gap-2">
                            <Input size="sm" label="Marca" value={row.brand}
                                onValueChange={(v) => patch(row.key, { brand: v })} />
                            <Input size="sm" label="Presentación" value={row.size}
                                onValueChange={(v) => patch(row.key, { size: v })} />
                        </div>
                        <div className="flex items-end gap-2">
                            <DecimalInput
                                size="sm" label="Cantidad" className="w-24"
                                value={items.find((i) => i.key === row.key)?.quantity ?? ''}
                                onValueChange={(v) => patch(row.key, { quantity: v })}
                            />
                            <DecimalInput
                                size="sm" label={inBolivares ? 'Precio (Bs)' : 'Precio ($)'}
                                value={items.find((i) => i.key === row.key)?.price ?? ''}
                                onValueChange={(v) => patch(row.key, { price: v })}
                            />
                            <div className="pb-2 text-right">
                                <p className="text-[10px] uppercase tracking-wide text-default-400">Subtotal</p>
                                <p className="text-sm font-semibold">{formatMoney(row.subtotal)}</p>
                            </div>
                        </div>
                    </Card>
                ))}
            </div>

            {/* Acciones */}
            <div className="mt-4 flex flex-col gap-2">
                <Button
                    fullWidth color="primary" radius="full" size="lg"
                    startContent={<Check size={18} />}
                    isLoading={processing}
                    isDisabled={!usable || missingRate}
                    onPress={confirmScan}
                >
                    {trip ? 'Añadir al mercado' : 'Crear la compra'}
                </Button>
                <Button fullWidth variant="light" radius="full" color="danger" onPress={discard}>
                    Descartar factura
                </Button>
            </div>
        </AppLayout>
    );
}
