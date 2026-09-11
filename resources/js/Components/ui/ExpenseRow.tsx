import { Trash2 } from 'lucide-react';
import { MemberBadge } from '@/Components/ui/primitives';
import ReceiptViewer from '@/Components/ui/ReceiptViewer';
import { formatBs, formatDate, formatEur, formatMoney, formatUsdt, paidEquivalents } from '@/lib/format';
import { Currency, Expense } from '@/types';

/**
 * Un gasto en una lista. Tocarlo abre la edición, que es donde se le adjunta
 * la factura.
 *
 * Debajo va lo mismo en bolívares, USDT y euros, a la tasa del día del gasto;
 * la moneda en que se pagó se destaca.
 */
export default function ExpenseRow({ expense, onEdit, onDelete, dateFormat = 'D MMM' }: {
    expense: Expense;
    onEdit: (expense: Expense) => void;
    onDelete: (expense: Expense) => void;
    dateFormat?: string;
}) {
    const eq = paidEquivalents(expense);
    const paidIn = (currency: Currency) =>
        expense.currency === currency ? 'font-medium text-default-600' : undefined;

    return (
        <div className="px-4 py-3">
            <div className="flex items-center gap-3">
                {expense.receipt_url ? (
                    <ReceiptViewer url={expense.receipt_url} alt={expense.description} size={36} />
                ) : (
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-content2 text-sm">
                        {expense.category?.name?.[0] ?? '·'}
                    </div>
                )}
                <button onClick={() => onEdit(expense)} className="min-w-0 flex-1 text-left active:opacity-60">
                    <p className="truncate text-sm font-medium">{expense.description}</p>
                    <p className="truncate text-xs text-default-400">
                        {expense.category?.name ?? 'Sin categoría'} · {formatDate(expense.date, dateFormat)}
                        {!expense.receipt_url && ' · sin comprobante'}
                    </p>
                </button>
                <MemberBadge member={expense.creator} size={22} />
                <button onClick={() => onEdit(expense)} className="shrink-0 font-semibold active:opacity-60">
                    {formatMoney(expense.amount)}
                </button>
                <button onClick={() => onDelete(expense)} aria-label="Eliminar gasto" className="shrink-0 text-default-300 active:text-rose-500">
                    <Trash2 size={16} />
                </button>
            </div>
            {/* Sin tasas congeladas (un gasto de antes de que hubiera tasas) solo hay dólares */}
            {eq.bcv !== null && (
                <p className="mt-1 truncate pl-12 text-[11px] tabular-nums text-default-400">
                    <span className={paidIn('VES')}>{formatBs(eq.bcv)}</span>
                    {' · '}<span className={paidIn('USDT')}>{formatUsdt(eq.usdt)}</span>
                    {' · '}<span className={paidIn('EUR')}>{formatEur(eq.eur)}</span>
                </p>
            )}
        </div>
    );
}
