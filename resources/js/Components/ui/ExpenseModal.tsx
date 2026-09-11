import { FormEvent, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    Button, Input, Modal, ModalBody, ModalContent, ModalFooter, ModalHeader, Select, SelectItem,
} from '@heroui/react';
import DecimalInput from '@/Components/ui/DecimalInput';
import ReceiptPicker from '@/Components/ui/ReceiptPicker';
import { formatBs, formatDate, formatIn, paidEquivalents, parseDecimal, toUsd, today } from '@/lib/format';
import { useRatesOn } from '@/lib/rates';
import { Currency, Expense, ExpenseCategory } from '@/types';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    categories: ExpenseCategory[];
    /** Gasto que ya existe. Si viene, el modal edita en vez de crear. */
    expense?: Expense | null;
}

const CURRENCIES: { key: Currency; label: string; hint: string }[] = [
    { key: 'USD', label: '$', hint: 'dólar BCV' },
    { key: 'VES', label: 'Bs', hint: 'bolívares' },
    { key: 'USDT', label: 'USDT', hint: 'paralelo' },
    { key: 'EUR', label: '€', hint: 'euro BCV' },
];

// La moneda del último gasto anotado en este dispositivo: quien paga casi
// todo en bolívares no debería tener que elegirlo cada vez.
const LAST_CURRENCY = 'whitewater.expenseCurrency';

function lastCurrency(): Currency {
    try {
        const saved = localStorage.getItem(LAST_CURRENCY);
        return CURRENCIES.some((c) => c.key === saved) ? (saved as Currency) : 'USD';
    } catch {
        return 'USD';
    }
}

/**
 * Alta y edición de un gasto, en el mismo formulario.
 *
 * El monto se escribe en la moneda en que se pagó; el servidor lo pasa a
 * dólares BCV con la tasa del día del gasto. Aquí se enseña esa misma cuenta
 * mientras se escribe, con las mismas tasas.
 *
 * Poder editar es lo que permite adjuntarle la factura a un gasto viejo, que
 * es como se usa de verdad: primero se anota el monto, el recibo aparece luego.
 */
export default function ExpenseModal({ isOpen, onClose, categories, expense = null }: Props) {
    const editing = !!expense;

    const [amount, setAmount] = useState('');
    const [currency, setCurrency] = useState<Currency>('USD');
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
        setAmount(expense ? String(Number(expense.original_amount)) : '');
        setCurrency(expense?.currency ?? lastCurrency());
        setDescription(expense?.description ?? '');
        setCategory(expense?.expense_category_id ? String(expense.expense_category_id) : '');
        setDate(expense?.date?.slice(0, 10) ?? today());
        setReceipt(null);
        setRemoveReceipt(false);
        setErrors({});
    }, [isOpen, expense]);

    const { rates, loading } = useRatesOn(date);
    const paid = parseDecimal(amount);
    const usd = paid !== null ? toUsd(paid, currency, rates) : null;
    const eq = paid !== null && usd !== null && rates
        ? paidEquivalents({ amount: usd, currency, original_amount: paid, rates })
        : null;
    const missingRate = currency !== 'USD' && !loading && !rates?.bcv_usd;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        // forceFormData por la foto; y con multipart PHP no rellena $_FILES en
        // un PATCH real, así que el método viaja suplantado en el cuerpo.
        router.post(editing ? `/finanzas/gastos/${expense.id}` : '/finanzas/gastos', {
            ...(editing ? { _method: 'patch' } : {}),
            amount,
            currency,
            description,
            expense_category_id: category,
            date,
            receipt,
            remove_receipt: removeReceipt ? '1' : '',
        }, {
            forceFormData: true,
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => {
                try { localStorage.setItem(LAST_CURRENCY, currency); } catch { /* sin almacenamiento */ }
                onClose();
            },
            onFinish: () => setProcessing(false),
        });
    };

    const symbol = CURRENCIES.find((c) => c.key === currency)?.label ?? '$';

    return (
        <Modal isOpen={isOpen} onClose={onClose} placement="center" backdrop="blur" size="sm">
            <ModalContent>
                <form onSubmit={submit}>
                    <ModalHeader>{editing ? 'Editar gasto' : 'Nuevo gasto'}</ModalHeader>
                    <ModalBody className="gap-3">
                        {/* En qué se pagó */}
                        <div className="flex gap-1 rounded-2xl bg-content2 p-1" role="radiogroup" aria-label="Moneda">
                            {CURRENCIES.map((c) => {
                                const on = currency === c.key;
                                return (
                                    <button
                                        key={c.key} type="button" role="radio" aria-checked={on}
                                        onClick={() => setCurrency(c.key)}
                                        className={`flex flex-1 flex-col items-center rounded-xl py-1.5 transition active:scale-95 ${
                                            on ? 'bg-content1 text-primary shadow-soft' : 'text-default-500'
                                        }`}
                                    >
                                        <span className="text-sm font-semibold">{c.label}</span>
                                        <span className="text-[10px] opacity-70">{c.hint}</span>
                                    </button>
                                );
                            })}
                        </div>

                        <div>
                            <DecimalInput
                                autoFocus label="Monto" startContent={symbol} size="lg"
                                value={amount} onValueChange={setAmount}
                                isInvalid={!!errors.amount} errorMessage={errors.amount} isRequired
                            />
                            {/* La misma cuenta que hará el servidor al guardar */}
                            {eq && (
                                <div className="mt-1.5 px-1 text-xs text-default-500">
                                    <p className="tabular-nums">
                                        = {(['USD', 'VES', 'USDT', 'EUR'] as Currency[])
                                            .filter((c) => c !== currency)
                                            .map((c) => formatIn({ USD: eq.usd, VES: eq.bcv, USDT: eq.usdt, EUR: eq.eur }[c], c))
                                            .join(' · ')}
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-default-400">
                                        {rates && rates.date !== date
                                            ? `No hay tasa del ${formatDate(date)}: se usa la del ${formatDate(rates.date)}. `
                                            : `Tasa del ${formatDate(rates!.date)}: `}
                                        BCV {formatBs(rates!.bcv_usd)} · USDT {formatBs(rates!.parallel_usd)}
                                    </p>
                                </div>
                            )}
                            {missingRate && (
                                <p className="mt-1.5 px-1 text-xs text-rose-500">
                                    No hay tasa guardada para convertir. Anótalo en dólares o actualiza las tasas desde el Inicio.
                                </p>
                            )}
                        </div>

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
                            isDisabled={!amount || !description || missingRate}
                        >
                            {editing ? 'Guardar' : 'Guardar gasto'}
                        </Button>
                    </ModalFooter>
                </form>
            </ModalContent>
        </Modal>
    );
}
