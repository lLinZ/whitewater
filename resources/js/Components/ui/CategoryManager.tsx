import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    Button, Input, Modal, ModalBody, ModalContent, ModalFooter, ModalHeader,
} from '@heroui/react';
import { Check, Pencil, Plus, Trash2, X } from 'lucide-react';
import { CHART_PALETTE } from '@/lib/accent';
import { ExpenseCategory } from '@/types';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    categories: ExpenseCategory[];
}

/**
 * Crear, renombrar, recolorear y borrar categorías de gasto.
 *
 * Borrar una no borra sus gastos: la clave foránea los deja en "Sin
 * categoría". Se avisa de cuántos son antes de confirmar.
 */
export default function CategoryManager({ isOpen, onClose, categories }: Props) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);

    const close = () => {
        setEditingId(null);
        setCreating(false);
        onClose();
    };

    return (
        <Modal isOpen={isOpen} onClose={close} placement="center" backdrop="blur" size="sm" scrollBehavior="inside">
            <ModalContent>
                <ModalHeader>Categorías de gasto</ModalHeader>
                <ModalBody className="gap-2">
                    {categories.length === 0 && !creating && (
                        <p className="py-4 text-center text-sm text-default-400">
                            Aún no hay categorías. Crea la primera.
                        </p>
                    )}

                    {categories.map((category) => (
                        editingId === category.id ? (
                            <CategoryForm
                                key={category.id}
                                category={category}
                                onDone={() => setEditingId(null)}
                            />
                        ) : (
                            <CategoryRow
                                key={category.id}
                                category={category}
                                onEdit={() => setEditingId(category.id)}
                            />
                        )
                    ))}

                    {creating && <CategoryForm onDone={() => setCreating(false)} />}
                </ModalBody>
                <ModalFooter className="justify-between">
                    <Button
                        variant="flat" radius="full" size="sm" startContent={<Plus size={15} />}
                        isDisabled={creating}
                        onPress={() => { setEditingId(null); setCreating(true); }}
                    >
                        Nueva categoría
                    </Button>
                    <Button variant="light" onPress={close}>Cerrar</Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
}

function CategoryRow({ category, onEdit }: { category: ExpenseCategory; onEdit: () => void }) {
    const used = category.expenses_count ?? 0;

    const del = () => {
        const warning = used > 0
            ? `¿Eliminar "${category.name}"? Sus ${used} gasto(s) quedarán sin categoría, no se borran.`
            : `¿Eliminar "${category.name}"?`;

        if (confirm(warning)) {
            router.delete(`/finanzas/categorias/${category.id}`, { preserveScroll: true });
        }
    };

    return (
        <div className="flex items-center gap-3 rounded-2xl bg-content2 px-3 py-2.5">
            <span
                className="h-3.5 w-3.5 shrink-0 rounded-full"
                style={{ background: category.color ?? '#a1a1aa' }}
            />
            <button onClick={onEdit} className="min-w-0 flex-1 text-left active:opacity-60">
                <p className="truncate text-sm font-medium">{category.name}</p>
                <p className="text-xs text-default-400">
                    {used === 0 ? 'Sin gastos' : `${used} gasto${used === 1 ? '' : 's'}`}
                </p>
            </button>
            <button onClick={onEdit} aria-label="Editar categoría" className="shrink-0 text-default-400 active:text-primary">
                <Pencil size={15} />
            </button>
            <button onClick={del} aria-label="Eliminar categoría" className="shrink-0 text-default-300 active:text-rose-500">
                <Trash2 size={15} />
            </button>
        </div>
    );
}

/** Mismo formulario para crear y para renombrar. */
function CategoryForm({ category, onDone }: { category?: ExpenseCategory; onDone: () => void }) {
    const [name, setName] = useState(category?.name ?? '');
    const [color, setColor] = useState(category?.color ?? CHART_PALETTE[0]);
    const [processing, setProcessing] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        const options = {
            preserveScroll: true,
            onSuccess: onDone,
            onFinish: () => setProcessing(false),
        };

        if (category) router.patch(`/finanzas/categorias/${category.id}`, { name, color }, options);
        else router.post('/finanzas/categorias', { name, color }, options);
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-2 rounded-2xl border border-primary/40 p-3">
            <Input
                autoFocus size="sm" label="Nombre" placeholder="Mercado"
                value={name} onValueChange={setName} isRequired
            />
            <div className="flex flex-wrap gap-1.5">
                {CHART_PALETTE.map((hex) => (
                    <button
                        key={hex} type="button" onClick={() => setColor(hex)}
                        aria-label={`Color ${hex}`} aria-pressed={color === hex}
                        style={{ background: hex }}
                        className="flex h-7 w-7 items-center justify-center rounded-full text-white transition active:scale-90"
                    >
                        {color === hex && <Check size={14} strokeWidth={3} />}
                    </button>
                ))}
            </div>
            <div className="flex gap-2">
                <Button
                    size="sm" variant="flat" radius="full" startContent={<X size={14} />}
                    onPress={onDone}
                >
                    Cancelar
                </Button>
                <Button
                    size="sm" color="primary" radius="full" type="submit"
                    isLoading={processing} isDisabled={!name.trim()}
                >
                    {category ? 'Guardar' : 'Crear'}
                </Button>
            </div>
        </form>
    );
}
