import { FormEvent, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    Button, Input, Modal, ModalBody, ModalContent, ModalFooter, ModalHeader, Select, SelectItem,
} from '@heroui/react';
import DecimalInput from '@/Components/ui/DecimalInput';
import ReceiptPicker from '@/Components/ui/ReceiptPicker';
import { today } from '@/lib/format';
import { Expense, ExpenseCategory } from '@/types';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    categories: ExpenseCategory[];
    /** Gasto que ya existe. Si viene, el modal edita en vez de crear. */
    expense?: Expense | null;
}

/**
 * Alta y edición de un gasto, en el mismo formulario.
 *
 * Poder editar es lo que permite adjuntarle la factura a un gasto viejo, que
 * es como se usa de verdad: primero se anota el monto, el recibo aparece luego.
 */
export default function ExpenseModal({ isOpen, onClose, categories, expense = null }: Props) {
    const editing = !!expense;

    const [amount, setAmount] = useState('');
    const [description, setDescription] = useState('');
    const [category, setCategory] = useState('');
    const [date, setDate] = useState(today());
    const [receipt, setReceipt] = useState<File | null>(null);
    const [removeReceipt, setRemoveReceipt] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    // Se rellena al abrir: editar un gasto tras otro no debe arrastrar lo
    // que quedó escrito del anterior.
    useEffect(() => {
        if (!isOpen) return;
        setAmount(expense ? String(expense.amount) : '');
        setDescription(expense?.description ?? '');
        setCategory(expense?.expense_category_id ? String(expense.expense_category_id) : '');
        setDate(expense?.date?.slice(0, 10) ?? today());
        setReceipt(null);
        setRemoveReceipt(false);
        setErrors({});
    }, [isOpen, expense]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        // forceFormData por la foto; y con multipart PHP no rellena $_FILES en
        // un PATCH real, así que el método viaja suplantado en el cuerpo.
        router.post(editing ? `/finanzas/gastos/${expense.id}` : '/finanzas/gastos', {
            ...(editing ? { _method: 'patch' } : {}),
            amount,
            description,
            expense_category_id: category,
            date,
            receipt,
            remove_receipt: removeReceipt ? '1' : '',
        }, {
            forceFormData: true,
            preserveScroll: true,
            onError: setErrors,
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} placement="center" backdrop="blur" size="sm">
            <ModalContent>
                <form onSubmit={submit}>
                    <ModalHeader>{editing ? 'Editar gasto' : 'Nuevo gasto'}</ModalHeader>
                    <ModalBody className="gap-3">
                        <DecimalInput
                            autoFocus label="Monto" startContent="$" size="lg"
                            value={amount} onValueChange={setAmount}
                            isInvalid={!!errors.amount} errorMessage={errors.amount} isRequired
                        />
                        <Input
                            label="Descripción" placeholder="Mercado del mes"
                            value={description} onValueChange={setDescription}
                            isInvalid={!!errors.description} errorMessage={errors.description} isRequired
                        />
                        <Select
                            label="Categoría"
                            selectedKeys={category ? [category] : []}
                            onSelectionChange={(keys) => setCategory(String(Array.from(keys)[0] ?? ''))}
                        >
                            {categories.map((c) => <SelectItem key={String(c.id)}>{c.name}</SelectItem>)}
                        </Select>
                        <Input type="date" label="Fecha" value={date} onValueChange={setDate} />
                        <ReceiptPicker
                            value={receipt}
                            onChange={setReceipt}
                            currentUrl={expense?.receipt_url}
                            removed={removeReceipt}
                            onRemovedChange={setRemoveReceipt}
                            error={errors.receipt}
                            hint="Foto del recibo o la factura (opcional)"
                        />
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={onClose}>Cancelar</Button>
                        <Button
                            color="primary" type="submit" isLoading={processing}
                            isDisabled={!amount || !description}
                        >
                            {editing ? 'Guardar' : 'Guardar gasto'}
                        </Button>
                    </ModalFooter>
                </form>
            </ModalContent>
        </Modal>
    );
}
