import { FormEvent, useState } from 'react';
import {
    Modal, ModalContent, ModalHeader, ModalBody, ModalFooter, Button, Input,
} from '@heroui/react';
import { router } from '@inertiajs/react';
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
    const [processing, setProcessing] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.post(action, { amount, note, date }, {
            preserveScroll: true,
            onSuccess: () => { setAmount(''); setNote(''); onClose(); },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} placement="center" backdrop="blur" size="sm">
            <ModalContent>
                <form onSubmit={submit}>
                    <ModalHeader>{title}</ModalHeader>
                    <ModalBody className="gap-3">
                        <Input
                            autoFocus
                            type="number"
                            step="0.01"
                            min="0"
                            label={amountLabel}
                            startContent={<span className="text-default-400">$</span>}
                            value={amount}
                            onValueChange={setAmount}
                            isRequired
                            size="lg"
                        />
                        <Input type="date" label="Fecha" value={date} onValueChange={setDate} />
                        {withNote && (
                            <Input label="Nota (opcional)" value={note} onValueChange={setNote} />
                        )}
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
