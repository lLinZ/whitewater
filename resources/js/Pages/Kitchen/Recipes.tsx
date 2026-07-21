import { FormEvent, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    Button, useDisclosure, Modal, ModalContent, ModalHeader, ModalBody, ModalFooter,
    Input, Textarea, Select, SelectItem, Chip,
} from '@heroui/react';
import { Plus, Trash2, Clock, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import KitchenNav from '@/Components/ui/KitchenNav';
import { Card, EmptyState } from '@/Components/ui/primitives';
import DecimalInput from '@/Components/ui/DecimalInput';
import { Recipe, Ingredient } from '@/types';

interface Props { recipes: Recipe[]; ingredients: Ingredient[] }

const CATEGORIES = ['Desayuno', 'Almuerzo', 'Cena', 'Postre', 'Snack'];

interface Line { ingredient_id: string; quantity: string; unit: string }

export default function Recipes({ recipes, ingredients }: Props) {
    const modal = useDisclosure();
    const [editing, setEditing] = useState<Recipe | null>(null);
    const [title, setTitle] = useState('');
    const [cats, setCats] = useState<string[]>([]);
    const [prep, setPrep] = useState('');
    const [instructions, setInstructions] = useState('');
    const [lines, setLines] = useState<Line[]>([]);
    const [processing, setProcessing] = useState(false);

    const openNew = () => {
        setEditing(null); setTitle(''); setCats([]); setPrep(''); setInstructions(''); setLines([]);
        modal.onOpen();
    };
    const openEdit = (r: Recipe) => {
        setEditing(r); setTitle(r.title); setCats(r.category ?? []); setPrep(String(r.prep_time_minutes ?? ''));
        setInstructions(r.instructions ?? '');
        setLines((r.ingredients ?? []).map((i) => ({ ingredient_id: String(i.id), quantity: i.pivot?.quantity ?? '', unit: i.pivot?.unit ?? '' })));
        modal.onOpen();
    };
    const toggleCat = (c: string) => setCats((prev) => prev.includes(c) ? prev.filter((x) => x !== c) : [...prev, c]);
    const addLine = () => setLines((l) => [...l, { ingredient_id: '', quantity: '', unit: '' }]);
    const setLine = (idx: number, patch: Partial<Line>) => setLines((l) => l.map((x, i) => i === idx ? { ...x, ...patch } : x));
    const removeLine = (idx: number) => setLines((l) => l.filter((_, i) => i !== idx));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        const payload = {
            title, category: cats, prep_time_minutes: prep ? Number(prep) : 0, instructions,
            linked_ingredients: lines.filter((l) => l.ingredient_id).map((l) => ({
                ingredient_id: Number(l.ingredient_id), quantity: Number(l.quantity) || 0, unit: l.unit,
            })),
        };
        const opts = { preserveScroll: true, onSuccess: () => modal.onClose(), onFinish: () => setProcessing(false) };
        if (editing) router.put(`/cocina/recetas/${editing.id}`, payload, opts);
        else router.post('/cocina/recetas', payload, opts);
    };
    const del = (r: Recipe) => { if (confirm(`¿Eliminar "${r.title}"?`)) router.delete(`/cocina/recetas/${r.id}`, { preserveScroll: true }); };

    return (
        <AppLayout
            title="Recetas"
            subtitle="Tu recetario"
            right={<Button isIconOnly color="primary" radius="full" size="sm" onPress={openNew}><Plus size={18} /></Button>}
        >
            <Head title="Recetas" />
            <KitchenNav current="/cocina/recetas" />

            {recipes.length === 0 ? (
                <EmptyState emoji="📖" title="Sin recetas" hint="Crea tu primera receta con sus ingredientes." />
            ) : (
                <div className="flex flex-col gap-2">
                    {recipes.map((r) => (
                        <Card key={r.id} onClick={() => openEdit(r)}>
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <h3 className="font-semibold">{r.title}</h3>
                                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                        {(r.category ?? []).map((c) => (
                                            <Chip key={c} size="sm" variant="flat" color="secondary" className="bg-primary/10 text-primary">{c}</Chip>
                                        ))}
                                        {r.prep_time_minutes > 0 && (
                                            <span className="flex items-center gap-1 text-xs text-default-400"><Clock size={12} /> {r.prep_time_minutes} min</span>
                                        )}
                                    </div>
                                    <p className="mt-1 text-xs text-default-400">{r.ingredients?.length ?? 0} ingredientes</p>
                                </div>
                                <button onClick={(e) => { e.stopPropagation(); del(r); }} className="text-default-300 active:text-rose-500"><Trash2 size={16} /></button>
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            <Modal isOpen={modal.isOpen} onClose={modal.onClose} placement="center" backdrop="blur" scrollBehavior="inside" size="md">
                <ModalContent>
                    <form onSubmit={submit}>
                        <ModalHeader>{editing ? 'Editar receta' : 'Nueva receta'}</ModalHeader>
                        <ModalBody className="gap-4">
                            <Input autoFocus label="Título" value={title} onValueChange={setTitle} isRequired />
                            <div>
                                <p className="mb-1.5 text-sm text-default-500">Categorías</p>
                                <div className="flex flex-wrap gap-2">
                                    {CATEGORIES.map((c) => (
                                        <button type="button" key={c} onClick={() => toggleCat(c)}
                                            className={`rounded-full px-3 py-1 text-sm transition ${cats.includes(c) ? 'bg-primary text-primary-foreground' : 'bg-content2 text-default-500'}`}>
                                            {c}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <Input type="number" label="Tiempo de preparación (min)" value={prep} onValueChange={setPrep} />

                            <div>
                                <div className="mb-1.5 flex items-center justify-between">
                                    <p className="text-sm text-default-500">Ingredientes</p>
                                    <Button size="sm" variant="flat" radius="full" startContent={<Plus size={14} />} onPress={addLine}>Añadir</Button>
                                </div>
                                <div className="flex flex-col gap-2">
                                    {lines.map((l, idx) => (
                                        <div key={idx} className="flex items-center gap-1.5">
                                            <Select aria-label="Ingrediente" size="sm" className="flex-1" selectedKeys={l.ingredient_id ? [l.ingredient_id] : []}
                                                onSelectionChange={(k) => setLine(idx, { ingredient_id: String(Array.from(k)[0] ?? '') })}>
                                                {ingredients.map((i) => <SelectItem key={String(i.id)}>{i.name}</SelectItem>)}
                                            </Select>
                                            <DecimalInput aria-label="Cantidad" size="sm" className="w-16" placeholder="Cant." value={l.quantity} onValueChange={(v) => setLine(idx, { quantity: v })} />
                                            <Input aria-label="Unidad" size="sm" className="w-20" placeholder="unid." value={l.unit} onValueChange={(v) => setLine(idx, { unit: v })} />
                                            <button type="button" onClick={() => removeLine(idx)} className="text-default-300"><X size={16} /></button>
                                        </div>
                                    ))}
                                    {lines.length === 0 && <p className="text-xs text-default-400">Sin ingredientes vinculados.</p>}
                                </div>
                            </div>

                            <Textarea label="Instrucciones (opcional)" value={instructions} onValueChange={setInstructions} minRows={2} />
                        </ModalBody>
                        <ModalFooter>
                            <Button variant="light" onPress={modal.onClose}>Cancelar</Button>
                            <Button color="primary" type="submit" isLoading={processing} isDisabled={!title}>{editing ? 'Guardar' : 'Crear'}</Button>
                        </ModalFooter>
                    </form>
                </ModalContent>
            </Modal>
        </AppLayout>
    );
}
