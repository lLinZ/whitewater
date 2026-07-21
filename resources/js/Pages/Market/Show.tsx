import { FormEvent, useMemo, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
import {
    Button, Autocomplete, AutocompleteItem, useDisclosure, Modal, ModalContent, ModalHeader, ModalBody, ModalFooter, Switch,
} from '@heroui/react';
import { Plus, Trash2, ArrowUp, ArrowDown, Check, Pencil } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card } from '@/Components/ui/primitives';
import DecimalInput from '@/Components/ui/DecimalInput';
import { formatMoney, formatBs, formatEur, convertUsd, fromNow, parseDecimal } from '@/lib/format';
import { ShoppingTrip, ShoppingItem } from '@/types';

interface CatalogItem {
    name: string;
    brand: string | null;
    size: string | null;
    label: string;
    count: number;
    last_price: number | null;
    last_date: string | null;
}

interface Props {
    trip: ShoppingTrip;
    previous: { id: number; name: string; date: string; total_usd: number } | null;
    catalog: CatalogItem[];
}

interface Draft {
    name: string;
    brand: string;
    size: string;
    price: string;
    qty: string;
}

const EMPTY: Draft = { name: '', brand: '', size: '', price: '', qty: '1' };

/** Presentaciones típicas, para que las sugerencias no estén vacías al empezar. */
const COMMON_SIZES = ['1 kg', '500 g', '250 g', '200 g', '1 L', '500 ml', 'Unidad'];

const norm = (s: string) => s.trim().toLowerCase();

const flatInput = { inputWrapper: 'bg-default-100 group-data-[focus=true]:bg-default-100 shadow-none' };

const draftPayload = (d: Draft) => ({
    name: d.name.trim(),
    brand: d.brand.trim() || null,
    size: d.size.trim() || null,
    unit_price_usd: parseDecimal(d.price),
    quantity: parseDecimal(d.qty) ?? 1,
});

/**
 * Producto + marca + presentación. Elegir del catálogo rellena los tres
 * campos (y el último precio) de un toque; también se puede escribir libre.
 */
function ProductFields({
    draft, patch, catalog, nameRef, autoFocus = false,
}: {
    draft: Draft;
    patch: (p: Partial<Draft>) => void;
    catalog: CatalogItem[];
    nameRef?: React.RefObject<HTMLInputElement>;
    autoFocus?: boolean;
}) {
    // Marcas y presentaciones ya usadas para este producto; si es uno nuevo,
    // se ofrecen todas las conocidas.
    const { brands, sizes } = useMemo(() => {
        const forName = draft.name.trim()
            ? catalog.filter((c) => norm(c.name) === norm(draft.name))
            : [];
        const pool = forName.length ? forName : catalog;
        return {
            brands: [...new Set(pool.map((c) => c.brand).filter((b): b is string => !!b))],
            sizes: [...new Set([...pool.map((c) => c.size).filter((s): s is string => !!s), ...COMMON_SIZES])],
        };
    }, [catalog, draft.name]);

    return (
        <>
            <Autocomplete
                ref={nameRef} autoFocus={autoFocus} aria-label="Producto" placeholder="Producto"
                allowsCustomValue menuTrigger="input"
                size="md" radius="lg" variant="flat" className="w-full"
                inputValue={draft.name}
                onInputChange={(v) => patch({ name: v })}
                onSelectionChange={(key) => {
                    if (!key) return;
                    const found = catalog.find((c) => c.label === String(key));
                    if (!found) return;
                    patch({
                        name: found.name,
                        brand: found.brand ?? '',
                        size: found.size ?? '',
                        ...(found.last_price !== null ? { price: String(found.last_price) } : {}),
                    });
                }}
                inputProps={{ autoComplete: 'off', classNames: flatInput }}
            >
                {catalog.map((c) => (
                    <AutocompleteItem key={c.label} textValue={c.label}>
                        <div className="flex items-center justify-between gap-2">
                            <div className="min-w-0">
                                <p className="truncate">{c.name}</p>
                                {(c.brand || c.size) && (
                                    <p className="truncate text-xs text-default-400">
                                        {[c.brand, c.size].filter(Boolean).join(' · ')}
                                    </p>
                                )}
                            </div>
                            <span className="shrink-0 text-xs text-default-400">
                                {c.last_price !== null ? `$${c.last_price.toFixed(2)}` : ''}{c.count > 1 ? ` · ${c.count}×` : ''}
                            </span>
                        </div>
                    </AutocompleteItem>
                ))}
            </Autocomplete>

            <div className="flex items-center gap-2">
                <Autocomplete
                    aria-label="Marca" placeholder="Marca" allowsCustomValue menuTrigger="input"
                    size="md" radius="lg" variant="flat" className="flex-1"
                    inputValue={draft.brand}
                    onInputChange={(v) => patch({ brand: v })}
                    onSelectionChange={(key) => key && patch({ brand: String(key) })}
                    inputProps={{ autoComplete: 'off', classNames: flatInput }}
                >
                    {brands.map((b) => <AutocompleteItem key={b}>{b}</AutocompleteItem>)}
                </Autocomplete>
                <Autocomplete
                    aria-label="Presentación" placeholder="Presentación" allowsCustomValue menuTrigger="input"
                    size="md" radius="lg" variant="flat" className="flex-1"
                    inputValue={draft.size}
                    onInputChange={(v) => patch({ size: v })}
                    onSelectionChange={(key) => key && patch({ size: String(key) })}
                    inputProps={{ autoComplete: 'off', classNames: flatInput }}
                >
                    {sizes.map((s) => <AutocompleteItem key={s}>{s}</AutocompleteItem>)}
                </Autocomplete>
            </div>
        </>
    );
}

export default function MarketShow({ trip, previous, catalog }: Props) {
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [editing, setEditing] = useState<ShoppingItem | null>(null);
    const [editDraft, setEditDraft] = useState<Draft>(EMPTY);
    const nameRef = useRef<HTMLInputElement>(null);
    const finishModal = useDisclosure();
    const [asExpense, setAsExpense] = useState(true);

    const patch = (p: Partial<Draft>) => setDraft((d) => ({ ...d, ...p }));
    const patchEdit = (p: Partial<Draft>) => setEditDraft((d) => ({ ...d, ...p }));

    const total = convertUsd(trip.total_usd, trip.rates);
    const draftPrice = parseDecimal(draft.price);
    const livePreview = draftPrice !== null
        ? convertUsd(draftPrice * (parseDecimal(draft.qty) ?? 1), trip.rates)
        : null;
    const delta = previous ? trip.total_usd - previous.total_usd : null;
    const pending = trip.pending_price_count;

    // Último precio conocido de este producto+marca+presentación exacto.
    const matched = draft.name.trim()
        ? catalog.find((c) => c.label === [draft.name, draft.brand, draft.size]
            .map((p) => p.trim()).filter(Boolean).join(' · '))
        : undefined;

    const addItem = (e: FormEvent) => {
        e.preventDefault();
        if (!draft.name.trim()) return;
        router.post(`/mercado/${trip.id}/item`, draftPayload(draft), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setDraft(EMPTY);
                nameRef.current?.focus();
            },
        });
    };

    const openEdit = (it: ShoppingItem) => {
        setEditing(it);
        setEditDraft({
            name: it.name,
            brand: it.brand ?? '',
            size: it.size ?? '',
            price: it.unit_price_usd !== null ? String(it.unit_price_usd) : '',
            qty: String(it.quantity),
        });
    };

    const saveEdit = () => {
        if (!editing || !editDraft.name.trim()) return;
        router.patch(`/mercado/${trip.id}/item/${editing.id}`, draftPayload(editDraft), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setEditing(null),
        });
    };

    const delItem = (id: number) => router.delete(`/mercado/${trip.id}/item/${id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => setEditing(null),
    });

    const finish = () => {
        router.post(`/mercado/${trip.id}/terminar`, { as_expense: asExpense }, { onSuccess: () => finishModal.onClose() });
    };

    return (
        <AppLayout
            title={trip.name}
            subtitle={`${trip.item_count} productos${pending > 0 ? ` · ${pending} sin precio` : ''}`}
            back="/mercado"
            right={
                trip.status === 'active'
                    ? <Button size="sm" color="primary" radius="full" variant="flat" onPress={finishModal.onOpen}>Terminar</Button>
                    : <span className="text-xs font-medium text-emerald-500">Terminado ✓</span>
            }
        >
            <Head title={trip.name} />

            {/* Total en vivo */}
            <Card className="bg-gradient-to-br from-violet-500 to-purple-600 text-white">
                <p className="text-sm opacity-90">Total del mercado</p>
                <p className="mt-1 text-4xl font-extrabold tracking-tight">{formatMoney(trip.total_usd)}</p>
                <div className="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div className="rounded-2xl bg-white/15 py-2">
                        <p className="text-[10px] uppercase opacity-80">BCV</p>
                        <p className="text-sm font-bold tabular-nums">{formatBs(total.bcv)}</p>
                    </div>
                    <div className="rounded-2xl bg-white/15 py-2">
                        <p className="text-[10px] uppercase opacity-80">USDT</p>
                        <p className="text-sm font-bold tabular-nums">{formatBs(total.usdt)}</p>
                    </div>
                    <div className="rounded-2xl bg-white/15 py-2">
                        <p className="text-[10px] uppercase opacity-80">Euro</p>
                        <p className="text-sm font-bold tabular-nums">{formatEur(total.eur)}</p>
                    </div>
                </div>
                {pending > 0 && (
                    <p className="mt-3 rounded-2xl bg-white/15 px-3 py-2 text-center text-xs">
                        {pending} {pending === 1 ? 'producto' : 'productos'} sin precio — tócalo{pending === 1 ? '' : 's'} para completarlo{pending === 1 ? '' : 's'}
                    </p>
                )}
                {delta !== null && Math.abs(delta) > 0.005 && (
                    <div className="mt-3 flex items-center justify-center gap-1 text-sm">
                        {delta > 0 ? <ArrowUp size={14} /> : <ArrowDown size={14} />}
                        <span className="font-semibold">{formatMoney(Math.abs(delta))}</span>
                        <span className="opacity-80">{delta > 0 ? 'más' : 'menos'} que {previous?.name}</span>
                    </div>
                )}
            </Card>

            {/* Agregar producto */}
            {trip.status === 'active' && (
                <form onSubmit={addItem} className="mt-3">
                    <Card className="!p-3">
                        <div className="flex flex-col gap-2">
                            <ProductFields draft={draft} patch={patch} catalog={catalog} nameRef={nameRef} autoFocus />
                            <div className="flex items-center gap-2">
                                <DecimalInput
                                    size="md" radius="lg" variant="flat" className="flex-1" placeholder="Precio (opcional)"
                                    classNames={flatInput}
                                    startContent={<span className="text-sm text-default-400">$</span>}
                                    value={draft.price} onValueChange={(v) => patch({ price: v })}
                                />
                                <DecimalInput
                                    size="md" radius="lg" variant="flat" className="w-16" placeholder="Cant"
                                    classNames={flatInput}
                                    value={draft.qty} onValueChange={(v) => patch({ qty: v })}
                                />
                                <Button isIconOnly color="primary" radius="full" size="lg" type="submit" isDisabled={!draft.name.trim()} className="h-12 w-12 min-w-12 shrink-0 shadow-soft">
                                    <Plus size={20} />
                                </Button>
                            </div>
                        </div>
                        <AnimatePresence>
                            {matched && matched.last_price !== null && (
                                <motion.p
                                    key="hint"
                                    initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }}
                                    className="mt-2 px-1 text-xs text-default-400"
                                >
                                    🕐 La última vez lo compraste a <b className="text-default-500">{formatMoney(matched.last_price)}</b>
                                    {matched.last_date ? ` · ${fromNow(matched.last_date)}` : ''}
                                </motion.p>
                            )}
                            {livePreview && (
                                <motion.p
                                    key="preview"
                                    initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }}
                                    className="mt-1 px-1 text-xs text-default-500"
                                >
                                    = {formatBs(livePreview.bcv)} · {formatBs(livePreview.usdt)} (USDT) · {formatEur(livePreview.eur)}
                                </motion.p>
                            )}
                        </AnimatePresence>
                    </Card>
                </form>
            )}

            {/* Lista de productos */}
            <div className="mt-3 flex flex-col gap-2">
                {trip.items.length === 0 && (
                    <p className="py-8 text-center text-sm text-default-400">Agrega el primer producto 🛒</p>
                )}
                {trip.items.map((it) => {
                    const c = convertUsd(it.subtotal_usd, trip.rates);
                    const noPrice = it.unit_price_usd === null;
                    return (
                        <Card key={it.id} className="flex items-center gap-3 !py-2.5" onClick={() => openEdit(it)}>
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-medium">
                                    {it.name}
                                    {it.size && <span className="ml-1.5 rounded-md bg-default-100 px-1.5 py-0.5 text-[11px] font-normal text-default-500">{it.size}</span>}
                                </p>
                                <p className="truncate text-xs text-default-400">
                                    {it.brand && <span className="text-default-500">{it.brand} · </span>}
                                    {noPrice
                                        ? <span className="text-amber-500">Sin precio · toca para agregarlo</span>
                                        : <>{it.quantity} × {formatMoney(it.unit_price_usd)} · {formatBs(c.bcv)}</>}
                                </p>
                            </div>
                            {noPrice
                                ? <Pencil size={16} className="shrink-0 text-amber-500" />
                                : <span className="font-semibold tabular-nums">{formatMoney(it.subtotal_usd)}</span>}
                            {trip.status === 'active' && (
                                <button
                                    aria-label="Eliminar"
                                    onClick={(e) => { e.stopPropagation(); delItem(it.id); }}
                                    className="shrink-0 text-default-300 active:text-rose-500"
                                >
                                    <Trash2 size={16} />
                                </button>
                            )}
                        </Card>
                    );
                })}
            </div>

            {/* Editar producto */}
            <Modal isOpen={!!editing} onClose={() => setEditing(null)} placement="center" backdrop="blur" size="sm">
                <ModalContent>
                    <ModalHeader>Editar producto</ModalHeader>
                    <ModalBody className="gap-2">
                        <ProductFields draft={editDraft} patch={patchEdit} catalog={catalog} />
                        <div className="flex items-center gap-2">
                            <DecimalInput
                                size="md" radius="lg" variant="flat" className="flex-1" placeholder="Precio (opcional)"
                                classNames={flatInput}
                                startContent={<span className="text-sm text-default-400">$</span>}
                                value={editDraft.price} onValueChange={(v) => patchEdit({ price: v })}
                            />
                            <DecimalInput
                                size="md" radius="lg" variant="flat" className="w-20" placeholder="Cant"
                                classNames={flatInput}
                                value={editDraft.qty} onValueChange={(v) => patchEdit({ qty: v })}
                            />
                        </div>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={() => setEditing(null)}>Cancelar</Button>
                        <Button color="primary" isDisabled={!editDraft.name.trim()} onPress={saveEdit}>Guardar</Button>
                    </ModalFooter>
                </ModalContent>
            </Modal>

            {/* Terminar */}
            <Modal isOpen={finishModal.isOpen} onClose={finishModal.onClose} placement="center" backdrop="blur" size="sm">
                <ModalContent>
                    <ModalHeader>Terminar mercado</ModalHeader>
                    <ModalBody>
                        <p className="text-sm text-default-500">
                            Total: <b className="text-foreground">{formatMoney(trip.total_usd)}</b> · {formatBs(total.bcv)}
                        </p>
                        {pending > 0 && (
                            <p className="rounded-2xl bg-amber-100 px-3 py-2 text-xs text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">
                                Ojo: {pending} {pending === 1 ? 'producto no tiene' : 'productos no tienen'} precio y no {pending === 1 ? 'suma' : 'suman'} al total.
                            </p>
                        )}
                        <div className="mt-2 flex items-center justify-between rounded-2xl bg-content2 px-3 py-3">
                            <div>
                                <p className="text-sm font-medium">Guardar como gasto</p>
                                <p className="text-xs text-default-400">Se registra en Finanzas (categoría Mercado)</p>
                            </div>
                            <Switch isSelected={asExpense} onValueChange={setAsExpense} color="primary" />
                        </div>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={finishModal.onClose}>Cancelar</Button>
                        <Button color="primary" startContent={<Check size={16} />} onPress={finish}>Terminar</Button>
                    </ModalFooter>
                </ModalContent>
            </Modal>
        </AppLayout>
    );
}
