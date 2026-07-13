import { ReactNode } from 'react';
import { motion } from 'framer-motion';

export default function AuthShell({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
    return (
        <div className="flex min-h-[100dvh] flex-col items-center justify-center bg-background px-5 pb-safe pt-safe">
            <motion.div
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ ease: [0.16, 1, 0.3, 1] }}
                className="w-full max-w-sm"
            >
                <div className="mb-6 text-center">
                    <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-3xl bg-gradient-to-br from-violet-500 to-purple-600 text-3xl shadow-float">
                        🌊
                    </div>
                    <h1 className="text-2xl font-extrabold tracking-tight">{title}</h1>
                    <p className="mt-1 text-sm text-default-500">{subtitle}</p>
                </div>
                <div className="rounded-3xl bg-content1 p-6 shadow-card">{children}</div>
            </motion.div>
        </div>
    );
}
