import { useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Button, Input, Switch } from '@heroui/react';
import { AlertTriangle, Check, Plus, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, SectionHeader } from '@/Components/ui/primitives';
import DecimalInput from '@/Components/ui/DecimalInput';
import ReceiptViewer from '@/Components/ui/ReceiptViewer';
import { formatBs, formatMoney, parseDecimal, today } from '@/lib/format';
import { accent } from '@/lib/accent';
import { PageProps, Rates } from '@/types';

interface ScannedItem {
    name: string;
    brand: string | null;
    size: string | null;
    quantity: number;
    unit_price: number;
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
    rates: Rates;
}

/** Fila editable: los precios se guardan como texto hasta el envío. */
interface Draft {
    key: number;
    name: string;
    brand: string;
    size: string;
    quantity: string;
    price: string;
}

type RateKey = 'bcv' | 'parallel';

const CONFIDENCE: Record<Invoice['confidence'], { label: string; tone: string }> = {
    alta: { label: 'Lectura clara', tone: 'text-emerald-600 dark:text-emerald-400' },
    media: { label: 'Revisa los precios', tone: 'text-amber-600 dark:text-amber-400' },
    baja: { label: 'Lectura dudosa: revísalo todo', tone: 'text-rose-600 dark:text-rose-400' },
};

export default function MarketInvoice({ invoice, receiptUrl, rates }: Props) {
    const user = usePage<PageProps>().props.auth.user;
    const inBolivares = invoice.currency === 'VES';

    const [name, setName] = useState('');
    const [store, setStore] = useState(invoice.store ?? '');
    const [date, setDate] = useState(invoice.date ?? today());
    const [rateKey, setRateKey] = useState<RateKey>('parallel');
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
        })),
    );

    // Cuántos bolívares vale un dólar con la tasa elegida.
    const rate = rateKey === 'bcv' ? rates?.bcv_usd ?? null : rates?.parallel_usd ?? null;

    // Base imponible declarada: el subtotal, o el total menos el IVA.
    const declaredSubtotal = invoice.subtotal
        ?? (invoice.total !== null && invoice.tax !== null ? invoice.total - invoice.tax : null);
    const hasTax = (invoice.tax ?? 0) > 0 && declaredSubtotal !== null && declaredSubtotal > 0;

    /**
     * Cuánto sube cada línea al repartirle el IVA. Se ancla a la proporción de
     * la factura (total / base) y no a la suma en pantalla: así corregir un
     * precio no deforma el resto.
     */
    const taxFactor = includeTax && hasTax && invoice.total
        ? invoice.total / (declaredSubtotal as number)
        : 1;

    /** Pasa un precio de la factura a dólares. En dólares no se convierte. */
    const toUsd = (value: number): number => {
        const withTax = value * taxFactor;
        if (!inBolivares) return withTax;
        if (!rate || rate <= 0) return 0;
        return withTax / rate;
    };

    const rows = useMemo(
        () => items.map((item) => {
            const price = parseDecimal(item.price) ?? 0;
            const quantity = parseDecimal(item.quantity) ?? 1;
            return { ...item, price, quantity, usd: toUsd(price), subtotal: toUsd(price) * quantity };
        }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [items, rate, inBolivares, taxFactor],
    );

    // Suma de las líneas tal como vienen en la factura, sin IVA repartido.
    const linesTotal = rows.reduce((sum, r) => sum + r.price * r.quantity, 0);
    const totalOriginal = linesTotal * taxFactor;
    const totalUsd = rows.reduce((sum, r) => sum + r.subtotal, 0);

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
        { key: Math.max(0, ...current.map((i) => i.key)) + 1, name: '', brand: '', size: '', quantity: '1', price: '' },
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
        <AppLayout title="Factura escaneada" subtitle="Revisa antes de guardar" back="/mercado">
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
            <SectionHeader title="La compra" />
            <Card className="flex flex-col gap-3">
                <Input label="Nombre" placeholder={`Mercado ${date.slice(8, 10)}/${date.slice(5, 7)}`}
                    value={name} onValueChange={setName} />
                <Input label="Comercio" value={store} onValueChange={setStore} />
                <Input type="date" label="Fecha" value={date} onValueChange={setDate} />
            </Card>

            {/* Tasa: solo si la factura vino en bolívares */}
            {inBolivares && (
                <>
                    <SectionHeader title="Tasa de cambio" />
                    <Card className="flex flex-col gap-3">
                        <p className="text-xs text-default-500">
                            La factura está en bolívares. Elige con qué tasa valorarla; los precios se
                            guardan en dólares.
                        </p>
                        <div className="flex gap-2 rounded-2xl bg-content2 p-1">
                            {([
                                { key: 'bcv' as const, label: 'BCV', value: rates?.bcv_usd ?? null },
                                { key: 'parallel' as const, label: 'Paralelo / USDT', value: rates?.parallel_usd ?? null },
                            ]).map((option) => {
                                const on = rateKey === option.key;
                                return (
                                    <button
                                        key={option.key}
                                        onClick={() => setRateKey(option.key)}
                                        aria-pressed={on}
                                        disabled={!option.value}
                                        className={`flex flex-1 flex-col items-center rounded-xl py-2 text-xs font-medium transition active:scale-95 disabled:opacity-40 ${
                                            on ? 'bg-content1 text-primary shadow-soft' : 'text-default-500'
                                        }`}
                                    >
                                        <span>{option.label}</span>
                                        <span className="text-[11px] opacity-70">
                                            {option.value ? formatBs(option.value) : 'sin tasa'}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
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
                                {includeTax
                                    ? `Cada precio sube un ${Math.round((taxFactor - 1) * 100)}%: es lo que pagaste de verdad.`
                                    : 'Los precios quedan sin IVA, como en el estante.'}
                            </p>
                        </div>
                        <Switch size="sm" color="primary" isSelected={includeTax} onValueChange={setIncludeTax} />
                    </Card>
                </>
            )}

            {/* Totales */}
            <Card className={`mt-3 bg-gradient-to-br text-white ${accent(user.color).gradient}`}>
                <p className="text-xs opacity-90">Total de la compra</p>
                <p className="mt-1 text-3xl font-bold">{formatMoney(totalUsd)}</p>
                {inBolivares && (
                    <p className="mt-0.5 text-xs opacity-80">
                        {formatBs(totalOriginal)} a {rateKey === 'bcv' ? 'tasa BCV' : 'paralelo'}
                        {hasTax && (includeTax ? ' · IVA incluido' : ' · sin IVA')}
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
                    Crear la compra
                </Button>
                <Button fullWidth variant="light" radius="full" color="danger" onPress={discard}>
                    Descartar factura
                </Button>
            </div>
        </AppLayout>
    );
}
