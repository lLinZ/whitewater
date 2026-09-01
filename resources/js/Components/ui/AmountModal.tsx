import { FormEvent, useState } from 'react';
import {
    Modal, ModalContent, ModalHeader, ModalBody, ModalFooter, Button, Input,
} from '@heroui/react';
import { router } from '@inertiajs/react';
import DecimalInput from '@/Components/ui/DecimalInput';
import ReceiptPicker from '@/Components/ui/ReceiptPicker';
import { today } from '@/lib/format';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    title: string;
    action: string;          // URL para POST
    ctaLabel?: string;
    amountLabel?: string;
    withNote?: boolean;
}

export default function AmountModal({
    isOpen, onClose, title, action, ctaLabel = 'Registrar', amountLabel = 'Monto', withNote = true,
}: Props) {
    const [amount, setAmount] = useState('');
    const [note, setNote] = useState('');
    const [date, setDate] = useState(today());
    const [receipt, setReceipt] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const reset = () => {
        setAmount('');
        setNote('');
        setReceipt(null);
        setErrors({});
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        // forceFormData: sin esto la foto viajaría como un objeto vacío en JSON.
        router.post(action, { amount, note, date, receipt }, {
            forceFormData: true,
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => { reset(); onClose(); },
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
                            error={errors.receipt}
                            hint="Foto del recibo del banco (opcional)"
                        />
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="light" onPress={onClose}>Cancelar</Button>
                        <Button color="primary" type="submit" isLoading={processing} isDisabled={!amount}>
                            {ctaLabel}
                        </Button>
                    </ModalFooter>
                </form>
            </ModalContent>
        </Modal>
    );
}
