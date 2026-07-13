import { FormEvent, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
import {
    Button, Input, Autocomplete, AutocompleteItem, useDisclosure, Modal, ModalContent, ModalHeader, ModalBody, ModalFooter, Switch,
} from '@heroui/react';
import { Plus, Trash2, ArrowUp, ArrowDown, Check } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card } from '@/Components/ui/primitives';
import { formatMoney, formatBs, formatEur, convertUsd, fromNow } from '@/lib/format';
import { ShoppingTrip } from '@/types';

interface CatalogItem { name: string; count: number; last_price: number; last_date: string | null }

interface Props {
    trip: ShoppingTrip;
    previous: { id: number; name: string; date: string; total_usd: number } | null;
    catalog: CatalogItem[];
}

export default function MarketShow({ trip, previous, catalog }: Props) {
    const [name, setName] = useState('');
    const [price, setPrice] = useState('');
    const [qty, setQty] = useState('1');
    const nameRef = useRef<HTMLInputElement>(null);
    const finishModal = useDisclosure();
    const [asExpense, setAsExpense] = useState(true);

    const total = convertUsd(trip.total_usd, trip.rates);
    const livePreview = price ? convertUsd((parseFloat(price) || 0) * (parseFloat(qty) || 1), trip.rates) : null;
    const delta = previous ? trip.total_usd - previous.total_usd : null;
    const matched = name.trim()
        ? catalog.find((c) => c.name.toLowerCase() === name.trim().toLowerCase())
        : undefined;

    const addItem = (e: FormEvent) => {
        e.preventDefault();
        if (!name || !price) return;
        router.post(`/mercado/${trip.id}/item`, {
            name, unit_price_usd: price, quantity: qty || 1,
        }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setName(''); setPrice(''); setQty('1');
                nameRef.current?.focus();
            },
        });
    };

    const delItem = (id: number) => router.delete(`/mercado/${trip.id}/item/${id}`, { preserveScroll: true, preserveState: true });

    const finish = () => {
        router.post(`/mercado/${trip.id}/terminar`, { as_expense: asExpense }, { onSuccess: () => finishModal.onClose() });
    };

    return (
        <AppLayout
            title={trip.name}
            subtitle={`${trip.item_count} productos`}
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
                            <Autocomplete
                                ref={nameRef} autoFocus aria-label="Producto" placeholder="Producto"
                                allowsCustomValue menuTrigger="input"
                                size="md" radius="lg" variant="flat" className="w-full"
                                inputValue={name}
                                onInputChange={setName}
                                onSelectionChange={(key) => {
                                    if (!key) return;
                                    const found = catalog.find((c) => c.name === String(key));
                                    if (found) {
                                        setName(found.name);
                                        if (found.last_price) setPrice(String(found.last_price));
                                    }
                                }}
                                inputProps={{
                                    autoComplete: 'off',
                                    classNames: { inputWrapper: 'bg-default-100 group-data-[focus=true]:bg-default-100 shadow-none' },
                                }}
                            >
                                {catalog.map((c) => (
                                    <AutocompleteItem key={c.name} textValue={c.name}>
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="truncate">{c.name}</span>
                                            <span className="shrink-0 text-xs text-default-400">
                                                {c.last_price ? `$${c.last_price.toFixed(2)}` : ''}{c.count > 1 ? ` · ${c.count}×` : ''}
                                            </span>
                                        </div>
                                    </AutocompleteItem>
                                ))}
                            </Autocomplete>
                            <div className="flex items-center gap-2">
                                <Input
                                    autoComplete="off" size="md" radius="lg" variant="flat" className="flex-1" type="number" step="0.01" inputMode="decimal" placeholder="Precio en $"
                                    classNames={{ inputWrapper: 'bg-default-100 group-data-[focus=true]:bg-default-100 shadow-none' }}
                                    startContent={<span className="text-sm text-default-400">$</span>} value={price} onValueChange={setPrice}
                                />
                                <Input
                                    autoComplete="off" size="md" radius="lg" variant="flat" className="w-16" type="number" step="0.5" inputMode="decimal" placeholder="Cant"
                                    classNames={{ inputWrapper: 'bg-default-100 group-data-[focus=true]:bg-default-100 shadow-none' }}
                                    value={qty} onValueChange={setQty}
                                />
                                <Button isIconOnly color="primary" radius="full" size="lg" type="submit" isDisabled={!name || !price} className="h-12 w-12 min-w-12 shrink-0 shadow-soft">
                                    <Plus size={20} />
                                </Button>
                            </div>
                        </div>
                        <AnimatePresence>
                            {matched && matched.last_price > 0 && (
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
                    return (
                        <Card key={it.id} className="flex items-center gap-3 !py-2.5">
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-medium">{it.name}</p>
                                <p className="text-xs text-default-400">
                                    {it.quantity} × {formatMoney(it.unit_price_usd)} · {formatBs(c.bcv)}
                                </p>
                            </div>
                            <span className="font-semibold tabular-nums">{formatMoney(it.subtotal_usd)}</span>
                            {trip.status === 'active' && (
                                <button onClick={() => delItem(it.id)} className="text-default-300 active:text-rose-500">
                                    <Trash2 size={16} />
                                </button>
                            )}
                        </Card>
                    );
                })}
            </div>

            {/* Terminar */}
            <Modal isOpen={finishModal.isOpen} onClose={finishModal.onClose} placement="center" backdrop="blur" size="sm">
                <ModalContent>
                    <ModalHeader>Terminar mercado</ModalHeader>
                    <ModalBody>
                        <p className="text-sm text-default-500">
                            Total: <b className="text-foreground">{formatMoney(trip.total_usd)}</b> · {formatBs(total.bcv)}
                        </p>
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
