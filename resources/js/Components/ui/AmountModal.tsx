import { FormEvent, useEffect, useState } from 'react';
import {
    Modal, ModalContent, ModalHeader, ModalBody, ModalFooter, Button, Input,
} from '@heroui/react';
import { router } from '@inertiajs/react';
import DecimalInput from '@/Components/ui/DecimalInput';
import ReceiptPicker from '@/Components/ui/ReceiptPicker';
import { today } from '@/lib/format';
import { MoneyEntry } from '@/types';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    title: string;
    /** URL destino: la de alta al crear, la del movimiento al editar. */
    action: string;
    ctaLabel?: string;
    amountLabel?: string;
    withNote?: boolean;
    /** Movimiento que ya existe. Si viene, el modal edita en vez de crear. */
    entry?: MoneyEntry | null;
}

export default function AmountModal({
    isOpen, onClose, title, action, ctaLabel = 'Registrar', amountLabel = 'Monto',
    withNote = true, entry = null,
}: Props) {
    const editing = !!entry;

    const [amount, setAmount] = useState('');
    const [note, setNote] = useState('');
    const [date, setDate] = useState(today());
    const [receipt, setReceipt] = useState<File | null>(null);
    const [removeReceipt, setRemoveReceipt] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    // Al abrirlo se rellena con el movimiento a editar, o se deja en blanco.
    // Sin esto, editar un abono después de otro arrastraría el monto anterior.
    useEffect(() => {
        if (!isOpen) return;
        setAmount(entry ? String(entry.amount) : '');
        setNote(entry?.note ?? '');
        setDate(entry?.date ?? today());
        setReceipt(null);
        setRemoveReceipt(false);
        setErrors({});
    }, [isOpen, entry]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        // forceFormData porque va una foto; y con multipart, PHP no rellena
        // $_FILES en un PATCH real, así que el método se suplanta en el cuerpo.
        router.post(action, {
            ...(editing ? { _method: 'patch' } : {}),
            amount,
            note,
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
                    <ModalHeader>{title}</ModalHeader>
                    <ModalBody className="gap-3">
                        <DecimalInput
                            autoFocus
                            label={amountLabel}
                            startContent={<span className="text-default-400">$</span>}
                            value={amount}
                            onValueChange={setAmount}
                            isInvalid={!!errors.amount}
                            errorMessage={errors.amount}
                            isRequired
                            size="lg"
                        />
                        <Input type="date" label="Fecha" value={date} onValueChange={setDate} />
                        {withNote && (
                            <Input label="Nota (opcional)" value={note} onValueChange={setNote} />
                        )}
                        <ReceiptPicker
                            value={receipt}
                            onChange={setReceipt}
                            currentUrl={entry?.receipt_url}
                            removed={removeReceipt}
                            onRemovedChange={setRemoveReceipt}
                            error={errors.receipt}
                            hint="Foto del recibo del banco (opcional)"
                        />
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={onClose}>Cancelar</Button>
                        <Button color="primary" type="submit" isLoading={processing} isDisabled={!amount}>
                            {editing ? 'Guardar' : ctaLabel}
                        </Button>
                    </ModalFooter>
                </form>
            </ModalContent>
        </Modal>
    );
}
