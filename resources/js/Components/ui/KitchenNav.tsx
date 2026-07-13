import { Link } from '@inertiajs/react';

const ITEMS = [
    { href: '/cocina/menu', label: 'Menú' },
    { href: '/cocina/recetas', label: 'Recetas' },
    { href: '/cocina/inventario', label: 'Inventario' },
];

export default function KitchenNav({ current }: { current: string }) {
    return (
        <div className="mb-4 flex gap-1 rounded-full bg-content2 p-1">
            {ITEMS.map((i) => {
                const active = i.href === current;
                return (
                    <Link
                        key={i.href}
                        href={i.href}
                        className={`flex-1 rounded-full py-1.5 text-center text-sm font-medium transition ${
                            active ? 'bg-content1 text-foreground shadow-soft' : 'text-default-500'
                        }`}
                    >
                        {i.label}
                    </Link>
                );
            })}
        </div>
    );
}
