import { FormEvent, useRef, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Button, Input } from '@heroui/react';
import {
    Camera, Check, LogOut, Moon, Palette, Smartphone, Sun, Trash2, Wallet, Sparkles, PiggyBank, Receipt,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import NotificationsToggle from '@/Components/ui/NotificationsToggle';
import { Card, MemberBadge, SectionHeader } from '@/Components/ui/primitives';
import { formatDate, formatMoneyShort } from '@/lib/format';
import { accent } from '@/lib/accent';
import {
    COLOR_KEYS, COLOR_LABELS, MODE_LABELS, ThemeColor, ThemeMode,
    applyTheme, resolveColor, resolveMode, swatch,
} from '@/lib/theme';
import { Member, PageProps } from '@/types';

const EMOJIS = ['🙂', '😎', '🦊', '🐼', '🐨', '🦁', '🐸', '🐙', '🌻', '🌊', '⚡️', '🔥', '🍀', '⭐️', '💜', '👑'];

interface Stats {
    member_since: string | null;
    routines_done: number;
    routines_month: number;
    expenses_logged: number;
    expenses_month: number;
    contributed: number;
    debt_paid: number;
}

type Props = PageProps<{
    mustVerifyEmail: boolean;
    status?: string;
    stats: Stats;
    household: Member[];
}>;

export default function Edit({ mustVerifyEmail, status, stats, household }: Props) {
    const user = usePage<PageProps>().props.auth.user;

    return (
        <AppLayout title="Perfil" subtitle={user.name} back="/dashboard" hideAvatar>
            <Head title="Perfil" />

            <Hero memberSince={stats.member_since} />

            <SectionHeader title="Lo que has aportado" />
            <ContributionStats stats={stats} />

            <SectionHeader title="Apariencia" />
            <Appearance />

            <SectionHeader title="Tu foto" />
            <AvatarCard />

            <SectionHeader title="Tus datos" />
            <ProfileForm mustVerifyEmail={mustVerifyEmail} status={status} />

            <SectionHeader title="Notificaciones" />
            <NotificationsToggle />

            {household.length > 0 && (
                <>
                    <SectionHeader title="El hogar" />
                    <Card className="flex flex-wrap gap-4 !py-4">
                        {household.map((member) => (
                            <div key={member.id} className="flex min-w-[72px] flex-col items-center gap-1.5">
                                <MemberBadge member={member} size={44} />
                                <span className="max-w-[80px] truncate text-xs text-default-500">{member.name}</span>
                            </div>
                        ))}
                    </Card>
                </>
            )}

            <SectionHeader title="Seguridad" />
            <PasswordForm />

            <SectionHeader title="Sesión" />
            <Card className="flex flex-col gap-2 !py-3">
                <Button
                    variant="flat" radius="full" startContent={<LogOut size={16} />}
                    onPress={() => router.post('/logout')}
                >
                    Cerrar sesión
                </Button>
                <DeleteAccount />
            </Card>
        </AppLayout>
    );
}

/** Cabecera: quién eres, con tu foto y tu color. */
function Hero({ memberSince }: { memberSince: string | null }) {
    const user = usePage<PageProps>().props.auth.user;
    const a = accent(user.color);

    return (
        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
            <Card className={`flex items-center gap-4 bg-gradient-to-br text-white ${a.gradient}`}>
                {user.avatar_url ? (
                    <img
                        src={user.avatar_url}
                        alt={user.name}
                        className="h-20 w-20 shrink-0 rounded-full object-cover ring-4 ring-white/30"
                    />
                ) : (
                    <span className="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-white/20 text-4xl ring-4 ring-white/30">
                        {user.avatar_emoji ?? '🙂'}
                    </span>
                )}
                <div className="min-w-0">
                    <h2 className="truncate text-xl font-bold">{user.name}</h2>
                    <p className="truncate text-sm opacity-90">{user.email}</p>
                    {memberSince && (
                        <p className="mt-1 text-xs opacity-75">
                            En el hogar desde {formatDate(memberSince, 'MMMM [de] YYYY')}
                        </p>
                    )}
                </div>
            </Card>
        </motion.div>
    );
}

function ContributionStats({ stats }: { stats: Stats }) {
    const tiles = [
        {
            icon: <Sparkles size={15} />,
            label: 'Tareas hechas',
            value: String(stats.routines_done),
            sub: `${stats.routines_month} este mes`,
        },
        {
            icon: <Receipt size={15} />,
            label: 'Gastos registrados',
            value: String(stats.expenses_logged),
            sub: `${formatMoneyShort(stats.expenses_month)} este mes`,
        },
        {
            icon: <PiggyBank size={15} />,
            label: 'Aportado a metas',
            value: formatMoneyShort(stats.contributed),
        },
        {
            icon: <Wallet size={15} />,
            label: 'Abonado a deudas',
            value: formatMoneyShort(stats.debt_paid),
        },
    ];

    return (
        <div className="grid grid-cols-2 gap-3">
            {tiles.map((tile) => (
                <Card key={tile.label} className="flex flex-col gap-1">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-default-500">{tile.label}</span>
                        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-primary">
                            {tile.icon}
                        </span>
                    </div>
                    <div className="text-2xl font-bold tracking-tight">{tile.value}</div>
                    {tile.sub && <div className="text-xs text-default-400">{tile.sub}</div>}
                </Card>
            ))}
        </div>
    );
}

const MODES: { key: ThemeMode; icon: typeof Sun }[] = [
    { key: 'light', icon: Sun },
    { key: 'dark', icon: Moon },
    { key: 'system', icon: Smartphone },
];

/**
 * Color de la app y modo claro/oscuro.
 *
 * El cambio se pinta al instante y se guarda de fondo: esperar al servidor
 * para ver un color haría que elegir se sintiera lento.
 */
function Appearance() {
    const user = usePage<PageProps>().props.auth.user;
    const color = resolveColor(user.color);
    const mode = resolveMode(user.theme);

    const save = (patch: { color?: ThemeColor; theme?: ThemeMode }) => {
        applyTheme(patch.color ?? color, patch.theme ?? mode);
        router.patch('/profile/apariencia', patch, { preserveScroll: true, preserveState: true });
    };

    return (
        <Card className="flex flex-col gap-4">
            <div>
                <div className="mb-2.5 flex items-center gap-2">
                    <Palette size={15} className="text-primary" />
                    <p className="text-sm font-medium">Color de la app</p>
                    <span className="ml-auto text-xs text-default-400">{COLOR_LABELS[color]}</span>
                </div>
                <div className="grid grid-cols-5 gap-2.5">
                    {COLOR_KEYS.map((key) => (
                        <button
                            key={key}
                            onClick={() => save({ color: key })}
                            aria-label={COLOR_LABELS[key]}
                            aria-pressed={color === key}
                            className="flex aspect-square items-center justify-center rounded-2xl text-white transition active:scale-90"
                            style={{ background: swatch(key) }}
                        >
                            {color === key && <Check size={18} strokeWidth={3} />}
                        </button>
                    ))}
                </div>
            </div>

            <div>
                <p className="mb-2.5 text-sm font-medium">Modo</p>
                <div className="flex gap-2 rounded-2xl bg-content2 p-1">
                    {MODES.map(({ key, icon: Icon }) => {
                        const on = mode === key;
                        return (
                            <button
                                key={key}
                                onClick={() => save({ theme: key })}
                                aria-pressed={on}
                                className={`flex flex-1 items-center justify-center gap-1.5 rounded-xl py-2 text-xs font-medium transition active:scale-95 ${
                                    on ? 'bg-content1 text-primary shadow-soft' : 'text-default-500'
                                }`}
                            >
                                <Icon size={15} />
                                {MODE_LABELS[key]}
                            </button>
                        );
                    })}
                </div>
                {mode === 'system' && (
                    <p className="mt-2 px-1 text-xs text-default-400">
                        Sigue el ajuste de tu teléfono: claro de día, oscuro de noche.
                    </p>
                )}
            </div>
        </Card>
    );
}

/** Foto de perfil y emoji de respaldo. */
function AvatarCard() {
    const user = usePage<PageProps>().props.auth.user;
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const pick = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setError(null);
        setUploading(true);
        // multipart: Inertia lo detecta solo al mandar un File.
        router.post('/profile/avatar', { avatar: file }, {
            preserveScroll: true,
            forceFormData: true,
            onError: (errs) => setError(errs.avatar ?? 'No se pudo subir la foto'),
            onFinish: () => {
                setUploading(false);
                if (fileRef.current) fileRef.current.value = '';
            },
        });
    };

    const setEmoji = (value: string) =>
        router.patch('/profile/apariencia', { avatar_emoji: value }, { preserveScroll: true, preserveState: true });

    return (
        <Card className="flex flex-col items-center gap-3">
            <button
                onClick={() => fileRef.current?.click()}
                className="relative active:scale-95"
                aria-label="Cambiar foto de perfil"
            >
                {user.avatar_url ? (
                    <img src={user.avatar_url} alt={user.name} className="h-24 w-24 rounded-full object-cover" />
                ) : (
                    <span className="flex h-24 w-24 items-center justify-center rounded-full bg-primary/10 text-5xl">
                        {user.avatar_emoji ?? '🙂'}
                    </span>
                )}
                <span className="absolute bottom-0 right-0 flex h-8 w-8 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-soft">
                    <Camera size={15} />
                </span>
            </button>

            <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={pick} />

            <div className="flex items-center gap-2">
                <Button size="sm" variant="flat" radius="full" isLoading={uploading} onPress={() => fileRef.current?.click()}>
                    {user.avatar_url ? 'Cambiar foto' : 'Subir foto'}
                </Button>
                {user.avatar_url && (
                    <Button
                        size="sm" variant="light" radius="full" color="danger"
                        onPress={() => router.delete('/profile/avatar', { preserveScroll: true })}
                    >
                        Quitar
                    </Button>
                )}
            </div>

            {error && <p className="text-xs text-rose-500">{error}</p>}

            <div className="w-full">
                <p className="mb-2 text-center text-xs text-default-400">
                    {user.avatar_url ? 'Emoji de respaldo' : 'O elige un emoji'}
                </p>
                <div className="flex flex-wrap justify-center gap-1.5">
                    {EMOJIS.map((e) => (
                        <button
                            key={e} onClick={() => setEmoji(e)}
                            aria-pressed={user.avatar_emoji === e}
                            className={`flex h-9 w-9 items-center justify-center rounded-2xl text-lg transition active:scale-90 ${
                                user.avatar_emoji === e ? 'bg-primary/15 ring-2 ring-primary' : 'bg-default-100'
                            }`}
                        >
                            {e}
                        </button>
                    ))}
                </div>
            </div>
        </Card>
    );
}

function ProfileForm({ mustVerifyEmail, status }: { mustVerifyEmail: boolean; status?: string }) {
    const user = usePage<PageProps>().props.auth.user;
    const { data, setData, patch, errors, processing } = useForm({
        name: user.name,
        email: user.email,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        patch('/profile', { preserveScroll: true });
    };

    return (
        <Card>
            <form onSubmit={submit} className="flex flex-col gap-3">
                <Input
                    label="Nombre" value={data.name} onValueChange={(v) => setData('name', v)}
                    isInvalid={!!errors.name} errorMessage={errors.name} isRequired
                />
                <Input
                    label="Correo" type="email" autoComplete="username"
                    value={data.email} onValueChange={(v) => setData('email', v)}
                    isInvalid={!!errors.email} errorMessage={errors.email} isRequired
                />

                {mustVerifyEmail && user.email_verified_at === null && (
                    <p className="text-xs text-amber-500">
                        Tu correo no está verificado.{' '}
                        <button type="button" className="underline" onClick={() => router.post('/email/verification-notification')}>
                            Reenviar verificación
                        </button>
                    </p>
                )}
                {status === 'verification-link-sent' && (
                    <p className="text-xs text-emerald-500">Te enviamos un nuevo enlace de verificación.</p>
                )}

                <Button color="primary" radius="full" type="submit" isLoading={processing} className="self-start">
                    Guardar
                </Button>
            </form>
        </Card>
    );
}

function PasswordForm() {
    const { data, setData, put, errors, processing, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put('/password', {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Card>
            <form onSubmit={submit} className="flex flex-col gap-3">
                <Input
                    label="Contraseña actual" type="password" autoComplete="current-password"
                    value={data.current_password} onValueChange={(v) => setData('current_password', v)}
                    isInvalid={!!errors.current_password} errorMessage={errors.current_password}
                />
                <Input
                    label="Nueva contraseña" type="password" autoComplete="new-password"
                    value={data.password} onValueChange={(v) => setData('password', v)}
                    isInvalid={!!errors.password} errorMessage={errors.password}
                />
                <Input
                    label="Repite la nueva" type="password" autoComplete="new-password"
                    value={data.password_confirmation} onValueChange={(v) => setData('password_confirmation', v)}
                />
                <Button color="primary" radius="full" type="submit" isLoading={processing} className="self-start">
                    Cambiar contraseña
                </Button>
            </form>
        </Card>
    );
}

function DeleteAccount() {
    const [open, setOpen] = useState(false);
    const { data, setData, delete: destroy, errors, processing } = useForm({ password: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        destroy('/profile');
    };

    if (!open) {
        return (
            <Button
                variant="light" radius="full" color="danger" startContent={<Trash2 size={16} />}
                onPress={() => setOpen(true)}
            >
                Eliminar mi cuenta
            </Button>
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-2">
            <p className="text-xs text-default-500">
                Esto borra tu cuenta para siempre. Escribe tu contraseña para confirmar.
            </p>
            <Input
                type="password" label="Contraseña" autoComplete="current-password"
                value={data.password} onValueChange={(v) => setData('password', v)}
                isInvalid={!!errors.password} errorMessage={errors.password}
            />
            <div className="flex gap-2">
                <Button variant="flat" radius="full" onPress={() => setOpen(false)}>Cancelar</Button>
                <Button color="danger" radius="full" type="submit" isLoading={processing}>Eliminar</Button>
            </div>
        </form>
    );
}
