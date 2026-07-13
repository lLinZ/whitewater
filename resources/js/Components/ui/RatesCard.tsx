import { useState } from 'react';
import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { Card } from '@/Components/ui/primitives';
import { formatBs, fromNow } from '@/lib/format';
import { Rates } from '@/types';

function Cell({ label, value, tone }: { label: string; value: string; tone: string }) {
    return (
        <div className="flex-1 text-center">
            <p className={`text-[11px] font-semibold uppercase tracking-wide ${tone}`}>{label}</p>
            <p className="mt-0.5 text-sm font-bold tabular-nums">{value}</p>
        </div>
    );
}

export default function RatesCard({ rates }: { rates: Rates | null }) {
    const [loading, setLoading] = useState(false);

    const refresh = () => {
        setLoading(true);
        router.post('/tasas/refrescar', {}, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
        });
    };

    return (
        <Card className="!p-3">
            <div className="mb-2 flex items-center justify-between px-1">
                <span className="text-xs font-semibold text-default-500">
                    Tasas del día {rates?.fetched_at ? `· ${fromNow(rates.fetched_at)}` : ''}
                </span>
                <button
                    onClick={refresh}
                    className="flex items-center gap-1 text-xs font-medium text-primary active:opacity-60"
                >
                    <RefreshCw size={13} className={loading ? 'animate-spin' : ''} />
                    {loading ? 'Actualizando' : 'Actualizar'}
                </button>
            </div>
            {rates ? (
                <div className="flex items-stretch divide-x divide-divider rounded-2xl bg-content2 py-2">
                    <Cell label="BCV" value={formatBs(rates.bcv_usd)} tone="text-sky-500" />
                    <Cell label="USDT" value={formatBs(rates.parallel_usd)} tone="text-emerald-500" />
                    <Cell label="€ BCV" value={formatBs(rates.bcv_eur)} tone="text-violet-500" />
                </div>
            ) : (
                <p className="py-2 text-center text-sm text-default-400">
                    Sin tasas aún. Toca <b>Actualizar</b>.
                </p>
            )}
        </Card>
    );
}
