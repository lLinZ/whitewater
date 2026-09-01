import { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Input, Button } from '@heroui/react';
import AuthShell from '@/Layouts/AuthShell';
import { COLOR_LABELS, ThemeColor, swatch } from '@/lib/theme';

const EMOJIS = ['🧔', '👩', '🧑', '👨', '👧', '🧑‍🦱', '🐱', '🚀'];
// El color elegido aquí tiñe la app entera; luego se cambia desde el perfil.
const COLORS: ThemeColor[] = ['violet', 'indigo', 'blue', 'teal', 'emerald', 'amber', 'orange', 'rose', 'pink', 'slate'];

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        avatar_emoji: '🧔',
        color: 'violet',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <AuthShell title="Crear cuenta" subtitle="Únete al hogar">
            <Head title="Registro" />

            <form onSubmit={submit} className="flex flex-col gap-4">
                {/* Avatar + color */}
                <div>
                    <p className="mb-2 text-sm text-default-500">Tu avatar</p>
                    <div className="flex flex-wrap gap-2">
                        {EMOJIS.map((e) => (
                            <button type="button" key={e} onClick={() => setData('avatar_emoji', e)}
                                className={`flex h-10 w-10 items-center justify-center rounded-2xl text-xl transition ${
                                    data.avatar_emoji === e ? 'bg-primary/15 ring-2 ring-primary' : 'bg-content2'
                                }`}>
                                {e}
                            </button>
                        ))}
                    </div>
                    <p className="mb-2 mt-4 text-sm text-default-500">Tu color</p>
                    <div className="grid grid-cols-5 gap-2">
                        {COLORS.map((c) => (
                            <button type="button" key={c} onClick={() => setData('color', c)}
                                aria-label={COLOR_LABELS[c]} aria-pressed={data.color === c}
                                style={{ background: swatch(c) }}
                                className={`aspect-square rounded-2xl transition active:scale-90 ${data.color === c ? 'ring-2 ring-offset-2 ring-foreground/40' : 'opacity-70'}`} />
                        ))}
                    </div>
                </div>

                <Input label="Nombre" autoComplete="name" variant="bordered" autoFocus
                    value={data.name} onValueChange={(v) => setData('name', v)}
                    isInvalid={!!errors.name} errorMessage={errors.name} />
                <Input type="email" label="Correo" autoComplete="username" variant="bordered"
                    value={data.email} onValueChange={(v) => setData('email', v)}
                    isInvalid={!!errors.email} errorMessage={errors.email} />
                <Input type="password" label="Contraseña" autoComplete="new-password" variant="bordered"
                    value={data.password} onValueChange={(v) => setData('password', v)}
                    isInvalid={!!errors.password} errorMessage={errors.password} />
                <Input type="password" label="Confirmar contraseña" autoComplete="new-password" variant="bordered"
                    value={data.password_confirmation} onValueChange={(v) => setData('password_confirmation', v)}
                    isInvalid={!!errors.password_confirmation} errorMessage={errors.password_confirmation} />

                <Button type="submit" color="primary" size="lg" radius="lg" isLoading={processing} className="mt-2 font-semibold">
                    Crear cuenta
                </Button>
            </form>

            <p className="mt-5 text-center text-sm text-default-500">
                ¿Ya tienen cuenta? <Link href={route('login')} className="font-semibold text-primary">Entrar</Link>
            </p>
        </AuthShell>
    );
}
