import { FormEvent, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    Button, useDisclosure, Modal, ModalContent, ModalHeader, ModalBody, ModalFooter, Input,
} from '@heroui/react';
import { Plus, Minus, Trash2, ShoppingCart } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import KitchenNav from '@/Components/ui/KitchenNav';
import { Card, SectionHeader, EmptyState } from '@/Components/ui/primitives';
import { Ingredient } from '@/types';

interface Projection { ingredient_id: number; name: string; quantity: number; unit: string }
interface Props { ingredients: Ingredient[]; projections: Projection[] }

export default function Inventory({ ingredients, projections }: Props) {
    const add = useDisclosure();

    const adjust = (ing: Ingredient, delta: number) => {
        const stock = Math.max(0, parseFloat(ing.stock) + delta);
        router.put(`/cocina/inventario/${ing.id}`, {
            name: ing.name, category: ing.category, stock, unit: ing.unit, min_stock: ing.min_stock,
        }, { preserveScroll: true });
    };
    const del = (ing: Ingredient) => {
        if (confirm(`¿Eliminar "${ing.name}"?`)) router.delete(`/cocina/inventario/${ing.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout
            title="Inventario"
            subtitle="Alimentos en casa"
            right={<Button isIconOnly color="primary" radius="full" size="sm" onPress={add.onOpen}><Plus size={18} /></Button>}
        >
            <Head title="Inventario" />
            <KitchenNav current="/cocina/inventario" />

            {projections.length > 0 && (
                <Card className="mb-4 border border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10">
                    <div className="mb-2 flex items-center gap-2 font-semibold text-amber-700 dark:text-amber-400">
                        <ShoppingCart size={18} /> Lista de compras sugerida
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {projections.map((p) => (
                            <span key={p.ingredient_id} className="rounded-full bg-white px-3 py-1 text-sm shadow-soft dark:bg-content1">
                                {p.name} · {p.quantity} {p.unit}
                            </span>
                        ))}
                    </div>
                </Card>
            )}

            <SectionHeader title="Despensa" />
            {ingredients.length === 0 ? (
                <EmptyState emoji="🥫" title="Inventario vacío" hint="Agrega los alimentos que tienes en casa." />
            ) : (
                <div className="flex flex-col gap-2">
                    {ingredients.map((ing) => {
                        const stock = parseFloat(ing.stock);
                        const low = stock <= parseFloat(ing.min_stock);
                        return (
                            <Card key={ing.id} className="flex items-center gap-3 !py-2.5">
                                <div className="min-w-0 flex-1">
                                    <p className="flex items-center gap-2 font-medium">
                                        {ing.name}
                                        {low && <span className="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-600 dark:bg-rose-500/15">bajo</span>}
                                    </p>
                                    <p className="text-xs text-default-400">{ing.category ?? 'General'}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button isIconOnly size="sm" variant="flat" radius="full" onPress={() => adjust(ing, -1)}><Minus size={14} /></Button>
                                    <span className="w-16 text-center text-sm font-semibold tabular-nums">
                                        {stock % 1 === 0 ? stock : stock.toFixed(1)} <span className="text-xs font-normal text-default-400">{ing.unit}</span>
                                    </span>
                                    <Button isIconOnly size="sm" variant="flat" radius="full" onPress={() => adjust(ing, 1)}><Plus size={14} /></Button>
                                    <button onClick={() => del(ing)} className="text-default-300 active:text-rose-500"><Trash2 size={16} /></button>
                                </div>
                            </Card>
                        );
                    })}
                </div>
            )}

            <AddIngredientModal disclosure={add} />
        </AppLayout>
    );
}

function AddIngredientModal({ disclosure }: { disclosure: ReturnType<typeof useDisclosure> }) {
    const [form, setForm] = useState({ name: '', category: '', stock: '', unit: 'unidades', min_stock: '' });
    const [processing, setProcessing] = useState(false);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.post('/cocina/inventario', {
            ...form,
            stock: form.stock || 0,
            min_stock: form.min_stock || 0,
        }, {
            preserveScroll: true,
            onSuccess: () => { setForm({ name: '', category: '', stock: '', unit: 'unidades', min_stock: '' }); disclosure.onClose(); },
            onFinish: () => setProcessing(false),
        });
    };
    return (
        <Modal isOpen={disclosure.isOpen} onClose={disclosure.onClose} placement="center" backdrop="blur" size="sm">
            <ModalContent>
                <form onSubmit={submit}>
                    <ModalHeader>Nuevo alimento</ModalHeader>
                    <ModalBody className="gap-3">
                        <Input autoFocus label="Nombre" placeholder="Arroz" value={form.name} onValueChange={(v) => setForm({ ...form, name: v })} isRequired />
                        <Input label="Categoría (opcional)" placeholder="Granos" value={form.category} onValueChange={(v) => setForm({ ...form, category: v })} />
                        <div className="flex gap-2">
                            <Input className="flex-1" type="number" step="0.01" label="Cantidad" value={form.stock} onValueChange={(v) => setForm({ ...form, stock: v })} isRequired />
                            <Input className="flex-1" label="Unidad" value={form.unit} onValueChange={(v) => setForm({ ...form, unit: v })} isRequired />
                        </div>
                        <Input type="number" step="0.01" label="Stock mínimo (alerta)" value={form.min_stock} onValueChange={(v) => setForm({ ...form, min_stock: v })} />
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={disclosure.onClose}>Cancelar</Button>
                        <Button color="primary" type="submit" isLoading={processing} isDisabled={!form.name}>Guardar</Button>
                    </ModalFooter>
                </form>
            </ModalContent>
        </Modal>
    );
}
