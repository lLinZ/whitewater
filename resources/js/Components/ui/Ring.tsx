import { motion } from 'framer-motion';
import { ReactNode } from 'react';

interface RingProps {
    value: number; // 0 - 100
    size?: number;
    stroke?: number;
    color?: string;
    trackClass?: string;
    children?: ReactNode;
}

export default function Ring({
    value,
    size = 120,
    stroke = 12,
    // Variable CSS, no hex: así el anillo sigue al color de la app sin que
    // cada pantalla tenga que pasarle el acento a mano.
    color = 'var(--app-accent, #7c3aed)',
    trackClass = 'text-default-200',
    children,
}: RingProps) {
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    const clamped = Math.max(0, Math.min(100, value));
    const offset = circumference - (clamped / 100) * circumference;

    return (
        <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
            <svg width={size} height={size} className="-rotate-90">
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    strokeWidth={stroke}
                    className={trackClass}
                    stroke="currentColor"
                />
                <motion.circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke={color}
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    initial={{ strokeDashoffset: circumference }}
                    animate={{ strokeDashoffset: offset }}
                    transition={{ duration: 1, ease: [0.16, 1, 0.3, 1] }}
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
                {children}
            </div>
        </div>
    );
}
