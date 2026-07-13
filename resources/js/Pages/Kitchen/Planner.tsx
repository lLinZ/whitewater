import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    Button, useDisclosure, Modal, ModalContent, ModalHeader, ModalBody, ModalFooter, Select, SelectItem, Chip,
} from '@heroui/react';
import { Plus, Trash2, Flame } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import KitchenNav from '@/Components/ui/KitchenNav';
import { Card } from '@/Components/ui/primitives';
import { Recipe, WeeklyPlan } from '@/types';

interface Day { date: string; label: string; short: string; isToday: boolean }
interface Props { recipes: Recipe[]; plans: WeeklyPlan[]; serverDays: Day[] }

const MEALS = [
    { key: 'breakfast', label: 'Desayuno', emoji: '🌅' },
    { key: 'lunch', label: 'Almuerzo', emoji: '🍽️' },
    { key: 'dinner', label: 'Cena', emoji: '🌙' },
] as const;

export default function Planner({ recipes, plans, serverDays }: Props) {
    const todayIdx = Math.max(0, serverDays.findIndex((d) => d.isToday));
    const [dayIdx, setDayIdx] = useState(todayIdx);
    const [slot, setSlot] = useState<{ meal: string } | null>(null);
    const [recipeId, setRecipeId] = useState('');
    const modal = useDisclosure();

    const day = serverDays[dayIdx];
    const planFor = (meal: string) => plans.find((p) => p.date === day.date && p.meal_type === meal);

    const openSlot = (meal: string) => { setSlot({ meal }); setRecipeId(''); modal.onOpen(); };

    const assign = () => {
        if (!recipeId) return;
        router.post('/cocina/menu', { recipe_id: recipeId, date: day.date, meal_type: slot!.meal }, {
            preserveScroll: true, onSuccess: () => modal.onClose(),
        });
    };
    const cook = (id: number) => router.post(`/cocina/menu/${id}/cocinar`, {}, { preserveScroll: true });
    const remove = (id: number) => router.delete(`/cocina/menu/${id}`, { preserveScroll: true });

    return (
        <AppLayout title="Menú semanal" subtitle="Planifica las comidas">
            <Head title="Menú" />
            <KitchenNav current="/cocina/menu" />

            {/* Selector de día */}
            <div className="hide-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
                {serverDays.map((d, i) => (
                    <button
                        key={d.date}
                        onClick={() => setDayIdx(i)}
                        className={`flex min-w-[52px] flex-col items-center rounded-2xl px-3 py-2 transition ${
                            i === dayIdx ? 'bg-primary text-primary-foreground shadow-soft' : 'bg-content2 text-default-500'
                        }`}
                    >
                        <span className="text-[11px] uppercase">{d.short}</span>
                        <span className="text-lg font-bold leading-tight">{d.date.slice(8, 10)}</span>
                        {d.isToday && <span className={`h-1 w-1 rounded-full ${i === dayIdx ? 'bg-white' : 'bg-primary'}`} />}
                    </button>
                ))}
            </div>

            <div className="mt-4 flex flex-col gap-3">
                {MEALS.map((m) => {
                    const plan = planFor(m.key);
                    return (
                        <Card key={m.key} className="!p-3">
                            <div className="flex items-center gap-3">
                                <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-content2 text-xl">{m.emoji}</span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-[11px] uppercase tracking-wide text-default-400">{m.label}</p>
                                    {plan ? (
                                        <p className="truncate font-medium">{plan.recipe?.title ?? 'Receta'}</p>
                                    ) : (
                                        <button onClick={() => openSlot(m.key)} className="text-sm font-medium text-primary">+ Añadir comida</button>
                                    )}
                                </div>
                                {plan && (
                                    <div className="flex items-center gap-1">
                                        {plan.is_deducted ? (
                                            <Chip size="sm" color="success" variant="flat">Cocinado ✓</Chip>
                                        ) : (
                                            <Button size="sm" color="primary" variant="flat" radius="full" startContent={<Flame size={14} />} onPress={() => cook(plan.id)}>
                                                Cocinar
                                            </Button>
                                        )}
                                        <Button isIconOnly size="sm" variant="light" radius="full" onPress={() => remove(plan.id)}>
                                            <Trash2 size={16} className="text-default-400" />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </Card>
                    );
                })}
            </div>

            <p className="mt-4 px-1 text-xs text-default-400">
                Al marcar <b>Cocinar</b>, los ingredientes de la receta se descuentan del inventario automáticamente. 🍳
            </p>

            <Modal isOpen={modal.isOpen} onClose={modal.onClose} placement="center" backdrop="blur" size="sm">
                <ModalContent>
                    <ModalHeader>Añadir comida</ModalHeader>
                    <ModalBody>
                        {recipes.length === 0 ? (
                            <p className="text-sm text-default-500">Aún no tienes recetas. Créalas en la pestaña Recetas.</p>
                        ) : (
                            <Select label="Receta" selectedKeys={recipeId ? [recipeId] : []} onSelectionChange={(k) => setRecipeId(String(Array.from(k)[0] ?? ''))}>
                                {recipes.map((r) => (
                                    <SelectItem key={String(r.id)} textValue={r.title}>
                                        <div className="flex items-center justify-between">
                                            <span>{r.title}</span>
                                            {r.is_available === false && <span className="text-xs text-amber-500">falta stock</span>}
                                        </div>
                                    </SelectItem>
                                ))}
                            </Select>
                        )}
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={modal.onClose}>Cancelar</Button>
                        <Button color="primary" onPress={assign} isDisabled={!recipeId}>Añadir</Button>
                    </ModalFooter>
                </ModalContent>
            </Modal>
        </AppLayout>
    );
}
