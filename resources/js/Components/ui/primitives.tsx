import { ReactNode } from 'react';
import { motion } from 'framer-motion';
import { accent } from '@/lib/accent';
import { Member } from '@/types';

export function Card({
    children,
    className = '',
    onClick,
}: {
    children: ReactNode;
    className?: string;
    onClick?: () => void;
}) {
    return (
        <div
            onClick={onClick}
            className={`rounded-3xl bg-content1 p-4 shadow-soft ${onClick ? 'cursor-pointer active:scale-[0.99] transition-transform' : ''} ${className}`}
        >
            {children}
        </div>
    );
}

export function SectionHeader({ title, action }: { title: string; action?: ReactNode }) {
    return (
        <div className="mb-2 mt-6 flex items-center justify-between px-1 first:mt-0">
            <h2 className="text-[13px] font-semibold uppercase tracking-wide text-default-500">{title}</h2>
            {action}
        </div>
    );
}

export function StatTile({
    label,
    value,
    sub,
    icon,
    tone = 'primary',
}: {
    label: string;
    value: ReactNode;
    sub?: ReactNode;
    icon?: ReactNode;
    tone?: string;
}) {
    const a = accent(tone);
    return (
        <Card className="flex flex-col gap-1">
            <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-default-500">{label}</span>
                {icon && <span className={`flex h-7 w-7 items-center justify-center rounded-full ${a.bgSoft} ${a.text}`}>{icon}</span>}
            </div>
            <div className="text-2xl font-bold tracking-tight">{value}</div>
            {sub && <div className="text-xs text-default-400">{sub}</div>}
        </Card>
    );
}

export function EmptyState({ emoji, title, hint }: { emoji: string; title: string; hint?: string }) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            className="flex flex-col items-center justify-center gap-2 rounded-3xl border border-dashed border-divider py-12 text-center"
        >
            <div className="text-4xl">{emoji}</div>
            <p className="font-semibold">{title}</p>
            {hint && <p className="max-w-xs text-sm text-default-400">{hint}</p>}
        </motion.div>
    );
}

export function MemberBadge({ member, size = 28 }: { member?: Member | null; size?: number }) {
    if (!member) return null;
    const a = accent(member.color);

    // Con foto se muestra la foto; si no, el emoji del miembro.
    if (member.avatar_url) {
        return (
            <img
                src={member.avatar_url}
                alt={member.name}
                title={member.name}
                className="inline-block shrink-0 rounded-full object-cover"
                style={{ width: size, height: size }}
            />
        );
    }

    return (
        <span
            title={member.name}
            className={`inline-flex shrink-0 items-center justify-center rounded-full ${a.bgSoft}`}
            style={{ width: size, height: size, fontSize: size * 0.5 }}
        >
            {member.avatar_emoji}
        </span>
    );
}
